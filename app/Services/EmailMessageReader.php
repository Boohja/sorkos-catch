<?php

declare(strict_types=1);

namespace Catch\Services;

use RuntimeException;

final class EmailMessageReader
{
    public function __construct(private readonly EmailContentSanitizer $sanitizer)
    {
    }

    /** @return list<string> */
    public function recipientAddresses(string $rawHeaders, string $domain): array
    {
        $headers = $this->headers($rawHeaders);
        $values = [];
        foreach (['delivered-to', 'x-original-to', 'envelope-to', 'to', 'cc'] as $name) {
            foreach ($headers[$name] ?? [] as $value) {
                $values[] = $value;
            }
        }

        $quotedDomain = preg_quote(mb_strtolower($domain), '/');
        $addresses = [];
        foreach ($values as $value) {
            if (preg_match_all('/\bibx-[a-z2-7]{16}@' . $quotedDomain . '\b/i', $value, $matches)) {
                foreach ($matches[0] as $address) {
                    $addresses[] = mb_strtolower($address);
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /** @return array{subject: string, body: string, from: string, received_at: string, message_id: ?string, message_key: string, has_attachments: bool} */
    public function read(mixed $connection, int $uid, string $rawHeaders): array
    {
        $structure = imap_fetchstructure($connection, $uid, FT_UID);
        if ($structure === false) {
            throw new RuntimeException('The message MIME structure could not be read.');
        }

        $bodies = ['html' => null, 'plain' => null, 'has_attachments' => false];
        $this->collectBodies($connection, $uid, $structure, '', $bodies);
        $body = $bodies['html'] !== null
            ? $this->sanitizer->htmlToText($bodies['html'])
            : trim((string) $bodies['plain']);

        $overview = imap_fetch_overview($connection, (string) $uid, FT_UID);
        $overview = is_array($overview) && isset($overview[0]) ? $overview[0] : null;
        $headers = $this->headers($rawHeaders);
        $messageId = $this->first($headers, 'message-id');
        $messageId = $messageId !== null ? trim($this->utf8($messageId)) : null;
        $subject = $this->decodeHeader((string) ($overview->subject ?? $this->first($headers, 'subject') ?? ''));
        $from = $this->sender($connection, $uid, $headers);
        $received = isset($overview->udate) && (int) $overview->udate > 0
            ? gmdate(DATE_ATOM, (int) $overview->udate)
            : gmdate(DATE_ATOM);
        $messageKey = $messageId !== null && $messageId !== ''
            ? 'message-id:' . mb_strtolower(trim($messageId, "<> \t\r\n"))
            : 'fingerprint:' . hash('sha256', $rawHeaders . "\0" . $body);

        return [
            'subject' => trim($subject),
            'body' => $body,
            'from' => $from,
            'received_at' => $received,
            'message_id' => $messageId !== null ? mb_substr($messageId, 0, 998) : null,
            'message_key' => $messageKey,
            'has_attachments' => (bool) $bodies['has_attachments'],
        ];
    }

    /** @param array{html: ?string, plain: ?string, has_attachments: bool} $result */
    private function collectBodies(mixed $connection, int $uid, object $part, string $number, array &$result): void
    {
        if ((int) ($part->type ?? -1) === 1 && !empty($part->parts)) {
            foreach ($part->parts as $index => $child) {
                $childNumber = $number === '' ? (string) ($index + 1) : $number . '.' . ($index + 1);
                $this->collectBodies($connection, $uid, $child, $childNumber, $result);
            }
            return;
        }

        $parameters = $this->partParameters($part);
        $disposition = mb_strtolower((string) ($part->disposition ?? ''));
        $isAttachment = $disposition === 'attachment'
            || isset($parameters['filename'])
            || isset($parameters['name']);
        if ($isAttachment || (int) ($part->type ?? 0) !== 0) {
            $result['has_attachments'] = true;
            return;
        }

        $subtype = mb_strtolower((string) ($part->subtype ?? 'plain'));
        if (!in_array($subtype, ['plain', 'html'], true) || $result[$subtype] !== null) {
            return;
        }

        $raw = $number === ''
            ? imap_body($connection, $uid, FT_UID | FT_PEEK)
            : imap_fetchbody($connection, $uid, $number, FT_UID | FT_PEEK);
        if ($raw === false) {
            throw new RuntimeException('A message body part could not be read.');
        }
        $decoded = match ((int) ($part->encoding ?? 0)) {
            3 => base64_decode($raw, true),
            4 => quoted_printable_decode($raw),
            default => $raw,
        };
        if ($decoded === false) {
            throw new RuntimeException('A message body part has invalid transfer encoding.');
        }

        $charset = trim((string) ($parameters['charset'] ?? 'UTF-8'));
        if ($charset !== '' && strcasecmp($charset, 'UTF-8') !== 0) {
            $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
            if (is_string($converted)) {
                $decoded = $converted;
            }
        }
        $decoded = $this->utf8($decoded);
        $result[$subtype] = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $decoded);
    }

    /** @return array<string, string> */
    private function partParameters(object $part): array
    {
        $parameters = [];
        foreach (array_merge((array) ($part->parameters ?? []), (array) ($part->dparameters ?? [])) as $parameter) {
            if (isset($parameter->attribute, $parameter->value)) {
                $parameters[mb_strtolower((string) $parameter->attribute)] = (string) $parameter->value;
            }
        }

        return $parameters;
    }

    /** @return array<string, list<string>> */
    private function headers(string $raw): array
    {
        $raw = preg_replace("/\r?\n[ \t]+/", ' ', $raw) ?? $raw;
        $result = [];
        foreach (preg_split("/\r?\n/", $raw) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = mb_strtolower(trim($name));
            $result[$name][] = trim($value);
        }

        return $result;
    }

    /** @param array<string, list<string>> $headers */
    private function first(array $headers, string $name): ?string
    {
        return $headers[$name][0] ?? null;
    }

    /** @param array<string, list<string>> $headers */
    private function sender(mixed $connection, int $uid, array $headers): string
    {
        $header = imap_headerinfo($connection, imap_msgno($connection, $uid));
        $address = is_object($header) ? ($header->from[0] ?? null) : null;
        if ($address && isset($address->mailbox, $address->host)) {
            return mb_strtolower($this->utf8((string) $address->mailbox . '@' . (string) $address->host));
        }

        return $this->decodeHeader((string) ($this->first($headers, 'from') ?? ''));
    }

    private function decodeHeader(string $value): string
    {
        $decoded = '';
        foreach (imap_mime_header_decode($value) as $part) {
            $charset = (string) ($part->charset ?? 'UTF-8');
            $text = (string) ($part->text ?? '');
            $decoded .= $charset === 'default' || strcasecmp($charset, 'UTF-8') === 0
                ? $text
                : (string) @mb_convert_encoding($text, 'UTF-8', $charset);
        }

        return $this->utf8($decoded);
    }

    private function utf8(string $value): string
    {
        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}

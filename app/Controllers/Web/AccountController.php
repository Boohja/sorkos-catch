<?php

declare(strict_types=1);

namespace Catch\Controllers\Web;

use Catch\Core\Config;
use Catch\Core\View;
use Catch\Http\Response;
use Catch\Repositories\DeviceRepository;
use Catch\Repositories\CaptureRepository;
use Catch\Repositories\EmailInboxRepository;
use Catch\Services\AuthService;
use Catch\Services\Csrf;

final class AccountController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthService $auth,
        private readonly DeviceRepository $devices,
        private readonly EmailInboxRepository $emailInboxes,
        private readonly CaptureRepository $captures,
        private readonly Config $config,
        private readonly Csrf $csrf,
    ) {
    }

    public function profile(): void
    {
        $user = $this->user();

        $this->view->render('account/profile', [
            'title' => 'Profile',
            'user' => $user,
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function settings(): void
    {
        $this->view->render('account/settings', [
            'title' => 'Settings',
            'user' => $this->user(),
            'settingsTab' => 'general',
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function devices(): void
    {
        $user = $this->user();
        $devices = $this->devices->all($user['id']);

        foreach ($devices as &$device) {
            $device['url'] = $this->deviceUrl($device);
        }
        unset($device);

        $this->view->render('account/settings', [
            'title' => 'Settings · Devices',
            'user' => $user,
            'settingsTab' => 'devices',
            'devices' => $devices,
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function email(): void
    {
        $user = $this->user();
        $inboxes = $this->emailInboxes->all($user['id']);

        foreach ($inboxes as &$inbox) {
            $inbox['url'] = $this->emailUrl($inbox);
        }
        unset($inbox);

        $this->view->render('account/settings', [
            'title' => 'Settings · Email',
            'user' => $user,
            'settingsTab' => 'email',
            'emailInboxes' => $inboxes,
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function newEmail(): void
    {
        $this->view->render('email/form', [
            'title' => 'Create email address',
            'user' => $this->user(),
            'emailFormTitle' => 'Create email address',
            'emailFormAction' => '/settings/email',
            'emailFormSubmit' => 'Create address',
            'emailFormName' => $_SESSION['email_form_name'] ?? 'Catch Mail',
            'emailFormError' => $_SESSION['email_form_error'] ?? null,
            'emailFormEditing' => false,
            'emailFormAddress' => 'ibx-................@' . $this->emailDomain(),
            'csrf' => $this->csrf->token(),
        ]);
        unset($_SESSION['email_form_name'], $_SESSION['email_form_error']);
    }

    public function createEmail(): never
    {
        $user = $this->user();
        if (!$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/settings/email');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $_SESSION['email_form_name'] = $name;
            $_SESSION['email_form_error'] = 'Enter a name for this inbox.';
            Response::redirect('/settings/email/new');
        }

        $inbox = $this->emailInboxes->create($user['id'], $name);
        Response::redirect($this->emailUrl($inbox));
    }

    public function showEmail(\Base $f3, array $params): void
    {
        $user = $this->user();
        $inbox = $this->emailInboxes->find($this->emailId((string) ($params['inbox'] ?? '')), $user['id']);
        if (!$inbox) {
            Response::redirect('/settings/email');
        }

        $this->view->render('email/show', [
            'title' => $inbox['name'],
            'user' => $user,
            'emailInbox' => $inbox,
            'emailInboxUrl' => $this->emailUrl($inbox),
            'emailFormAction' => $this->emailUrl($inbox) . '/name',
            'emailFormName' => $inbox['name'],
            'emailFormSubmit' => 'Save name',
            'emailFormError' => null,
            'emailFormEditing' => true,
            'emailFormAddress' => $inbox['address'],
            'emailQrVcard' => base64_encode($this->buildVcard($inbox, false)),
            'captures' => $this->captures->listByEmailInbox($user['id'], $inbox['id']),
            'csrf' => $this->csrf->token(),
        ]);
    }

    public function renameEmail(\Base $f3, array $params): never
    {
        $user = $this->user();
        $id = $this->emailId((string) ($params['inbox'] ?? ''));
        $inbox = $this->emailInboxes->find($id, $user['id']);
        if (!$inbox || !$this->csrf->valid($_POST['_csrf'] ?? null)) {
            Response::redirect('/settings/email');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $_SESSION['flash_error'] = 'Enter a name for this inbox.';
        } else {
            $this->emailInboxes->rename($id, $user['id'], $name);
            $_SESSION['flash_success'] = 'Inbox name saved.';
        }
        $inbox = $this->emailInboxes->find($id, $user['id']) ?? $inbox;
        Response::redirect($this->emailUrl($inbox));
    }

    public function emailVcard(\Base $f3, array $params): never
    {
        $user = $this->user();
        $inbox = $this->emailInboxes->find($this->emailId((string) ($params['inbox'] ?? '')), $user['id']);
        if (!$inbox) {
            Response::redirect('/settings/email');
        }

        $vcard = $this->buildVcard($inbox, true);
        $filename = trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', (string) $inbox['name']), '-');
        $filename = $filename !== '' ? $filename : 'catch-mail';

        header('Content-Type: text/vcard; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.vcf"');
        header('Cache-Control: private, no-store');
        echo $vcard;
        exit;
    }

    public function revokeEmail(\Base $f3, array $params): never
    {
        $user = $this->user();
        $id = $this->emailId((string) ($params['inbox'] ?? ''));
        if ($this->csrf->valid($_POST['_csrf'] ?? null)) {
            $this->emailInboxes->revoke($id, $user['id']);
        }
        $inbox = $this->emailInboxes->find($id, $user['id']);
        Response::redirect($inbox ? $this->emailUrl($inbox) : '/settings/email');
    }

    private function user(): array
    {
        $user = $this->auth->user();
        if (!$user) {
            Response::redirect('/login');
        }

        return $user;
    }

    private function deviceUrl(array $device): string
    {
        $asciiName = iconv('UTF-8', 'ASCII//TRANSLIT', $device['name']) ?: $device['name'];
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $asciiName), '-'));

        return '/devices/' . $device['id'] . '-' . ($slug ?: 'device');
    }

    private function emailId(string $routeValue): string
    {
        return substr($routeValue, 0, 36);
    }

    private function emailUrl(array $inbox): string
    {
        $asciiName = iconv('UTF-8', 'ASCII//TRANSLIT', (string) $inbox['name']) ?: $inbox['name'];
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', (string) $asciiName), '-'));

        return '/settings/email/' . $inbox['id'] . '-' . ($slug ?: 'inbox');
    }

    private function emailDomain(): string
    {
        return mb_strtolower(trim((string) $this->config->get('mail.address_domain', 'catch.sorkos.net')));
    }

    private function buildVcard(array $inbox, bool $includePhoto): string
    {
        $escape = static fn (string $value): string => str_replace(
            ["\\", ";", ",", "\r\n", "\r", "\n"],
            ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"],
            $value,
        );
        $name = $escape((string) $inbox['name']);
        $address = $escape((string) $inbox['address']);
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nPRODID:-//Catch//Email Inbox//EN\r\n"
            . "N:;{$name};;;\r\nFN:{$name}\r\nEMAIL;TYPE=INTERNET:{$address}\r\n";
        if (filter_var($appUrl, FILTER_VALIDATE_URL)) {
            $vcard .= $this->foldVcardLine('URL:' . $escape($appUrl)) . "\r\n";
        }
        if ($includePhoto) {
            $logoPath = dirname(__DIR__, 3) . '/public/assets/logo/vcard.png';
            $logo = is_file($logoPath) ? file_get_contents($logoPath) : false;
            if ($logo !== false) {
                $vcard .= $this->foldVcardLine('PHOTO;ENCODING=b;TYPE=PNG:' . base64_encode($logo)) . "\r\n";
            }
        }

        return $vcard . "END:VCARD\r\n";
    }

    private function foldVcardLine(string $line): string
    {
        $folded = [substr($line, 0, 75)];
        $line = substr($line, 75);
        while ($line !== '') {
            $folded[] = ' ' . substr($line, 0, 74);
            $line = substr($line, 74);
        }

        return implode("\r\n", $folded);
    }
}

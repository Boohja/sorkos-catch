<?php

declare(strict_types=1);

namespace Catch\Core;

final class View
{
    public function __construct(private readonly string $path)
    {
    }

    public function render(string $template, array $data = [], int $httpStatus = 200): void
    {
        http_response_code($httpStatus);
        extract($data, EXTR_SKIP);
        $templateFile = $this->path . '/' . $template . '.php';
        ob_start();
        require $templateFile;
        $content = (string) ob_get_clean();
        require $this->path . '/layout.php';
    }
}

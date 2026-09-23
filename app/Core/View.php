<?php

namespace App\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class View
{
    private Environment $twig;

    public function __construct(string $templatePath, Auth $auth, array $config)
    {
        $this->twig = new Environment(new FilesystemLoader($templatePath), [
            'cache' => false,
            'autoescape' => 'html',
        ]);

        $this->twig->addGlobal('app_name', $config['app_name']);
        $this->twig->addGlobal('auth', $auth->user());
        $this->twig->addFunction(new TwigFunction('url', fn (string $path = ''): string => url($path)));
        $this->twig->addFunction(new TwigFunction('page_url', fn (string $path, string $parameter, int $page): string =>
            url($path) . '?' . http_build_query(array_replace($_GET, [$parameter => $page]), '', '&', PHP_QUERY_RFC3986)
        ));
        $this->twig->addFunction(new TwigFunction('csrf_field', fn (): string =>
            '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">',
            ['is_safe' => ['html']]
        ));
    }

    public function render(string $template, array $data = []): void
    {
        $data['flash_success'] = $_SESSION['flash_success'] ?? null;
        $data['flash_error'] = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
        echo $this->twig->render($template, $data);
    }
}

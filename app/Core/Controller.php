<?php

namespace App\Core;

use PDO;

abstract class Controller
{
    public function __construct(
        protected PDO $db,
        protected View $view,
        protected Auth $auth
    ) {
    }

    protected function redirect(string $path, ?string $success = null, ?string $error = null): never
    {
        if ($success) {
            $_SESSION['flash_success'] = $success;
        }
        if ($error) {
            $_SESSION['flash_error'] = $error;
        }
        header('Location: ' . url($path));
        exit;
    }

    protected function validateCsrf(): void
    {
        $expected = $_SESSION['csrf_token'] ?? null;
        $submitted = $_POST['csrf_token'] ?? null;
        if (!is_string($expected) || $expected === '' || !is_string($submitted) || $submitted === ''
            || !hash_equals($expected, $submitted)) {
            http_response_code(419);
            exit('Neispravan CSRF token. Osvežite stranicu i pokušajte ponovo.');
        }
    }

    protected function pageNumber(string $key = 'page'): int
    {
        $value = $_GET[$key] ?? 1;
        if (!is_string($value) && !is_int($value)) {
            return 1;
        }
        $page = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $page === false ? 1 : $page;
    }
}

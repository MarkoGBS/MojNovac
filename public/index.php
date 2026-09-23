<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\ManagerController;
use App\Controllers\ReportController;
use App\Controllers\UserController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Router;
use App\Core\View;

session_start();
require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
$GLOBALS['config'] = $config;

function url(string $path = ''): string
{
    $base = rtrim((string) ($GLOBALS['config']['base_url'] ?? ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

try {
    $db = Database::connect($config['db']);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Veza sa bazom nije uspela. Proverite config/config.php i uvezite database/schema.sql.');
}

$auth = new Auth($db);
$view = new View(dirname(__DIR__) . '/templates', $auth, $config);
$home = new HomeController($db, $view, $auth);
$authController = new AuthController($db, $view, $auth);
$user = new UserController($db, $view, $auth);
$manager = new ManagerController($db, $view, $auth);
$admin = new AdminController($db, $view, $auth);
$reports = new ReportController($db, $view, $auth);

$router = new Router();
$router->get('/', [$home, 'index']);
$router->get('/faq', [$home, 'faq']);
$router->get('/login', [$authController, 'showLogin']);
$router->post('/login', [$authController, 'login']);
$router->get('/register', [$authController, 'showRegister']);
$router->post('/register', [$authController, 'register']);
$router->get('/change-password', [$authController, 'showChangePassword']);
$router->post('/change-password', [$authController, 'changePassword']);
$router->post('/logout', [$authController, 'logout']);

$router->get('/dashboard', [$user, 'dashboard']);
$router->post('/accounts', [$user, 'addAccount']);
$router->post('/money', [$user, 'moneyAction']);
$router->post('/transfer', [$user, 'transfer']);
$router->post('/support', [$user, 'support']);

$router->get('/manager', [$manager, 'index']);
$router->get('/manager/users/{id}', [$manager, 'user']);
$router->post('/manager/users/{id}/budgets', [$manager, 'saveBudget']);
$router->post('/manager/users/{id}/budgets/{budgetId}/delete', [$manager, 'deleteBudget']);
$router->post('/manager/users/{id}/tickets/{ticketId}/reply', [$manager, 'replySupport']);

$router->get('/admin', [$admin, 'index']);
$router->post('/admin/users/{id}', [$admin, 'updateUser']);
$router->post('/admin/assignments', [$admin, 'assignManager']);
$router->post('/admin/assignments/{id}/delete', [$admin, 'deleteAssignment']);
$router->post('/admin/categories', [$admin, 'addCategory']);
$router->post('/admin/categories/{id}/toggle', [$admin, 'toggleCategory']);
$router->post('/admin/tickets/{id}', [$admin, 'ticketStatus']);

foreach (['transactions', 'monthly', 'budgets'] as $type) {
    foreach (['xlsx', 'pdf'] as $format) {
        $router->get('/admin/reports/' . $type . '/' . $format, fn () => $reports->download($type, $format));
    }
}

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

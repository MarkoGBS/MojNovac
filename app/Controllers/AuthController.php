<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\User;
use DomainException;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        $this->view->render('auth/login.twig');
    }

    public function login(): void
    {
        $this->validateCsrf();
        $input = array_filter($_POST, 'is_string');
        if ($this->auth->attempt(trim($input['email'] ?? ''), $input['password'] ?? '')) {
            $role = $this->auth->role();
            $this->redirect($role === 'admin' ? '/admin' : ($role === 'manager' ? '/manager' : '/dashboard'));
        }
        $this->redirect('/login', null, 'Pogrešan email ili lozinka.');
    }

    public function showRegister(): void
    {
        $old = $_SESSION['old_registration'] ?? [];
        unset($_SESSION['old_registration']);
        $this->view->render('auth/register.twig', [
            'old' => $old,
            'max_birth_date' => date('Y-m-d'),
        ]);
    }

    public function register(): void
    {
        $this->validateCsrf();
        $input = array_filter($_POST, 'is_string');
        $registration = [
            'first_name' => trim($input['first_name'] ?? ''),
            'last_name' => trim($input['last_name'] ?? ''),
            'date_of_birth' => trim($input['date_of_birth'] ?? ''),
            'email' => trim($input['email'] ?? ''),
        ];

        try {
            $userId = (new User($this->db))->register(
                $registration['first_name'],
                $registration['last_name'],
                $registration['date_of_birth'],
                $registration['email'],
                $input['password'] ?? '',
                $input['password_confirmation'] ?? ''
            );
        } catch (DomainException $e) {
            $_SESSION['old_registration'] = $registration;
            $this->redirect('/register', null, $e->getMessage());
        }

        unset($_SESSION['old_registration']);
        $this->auth->log($userId, 'Registracija naloga');
        $this->redirect('/login', 'Registracija je uspešna. Sada se prijavite.');
    }

    public function showChangePassword(): void
    {
        $this->auth->requireRole('user', 'manager', 'admin');
        $this->view->render('auth/change-password.twig');
    }

    public function changePassword(): void
    {
        $this->auth->requireRole('user', 'manager', 'admin');
        $this->validateCsrf();
        $input = array_filter($_POST, 'is_string');

        try {
            (new User($this->db))->changePassword(
                $this->auth->id(),
                $input['current_password'] ?? '',
                $input['new_password'] ?? '',
                $input['password_confirmation'] ?? ''
            );
        } catch (DomainException $e) {
            $this->redirect('/change-password', null, $e->getMessage());
        }

        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $this->auth->log($this->auth->id(), 'Promenjena lozinka');
        $this->redirect('/change-password', 'Lozinka je uspešno promenjena.');
    }

    public function logout(): void
    {
        $this->validateCsrf();
        $this->auth->logout();
        $this->redirect('/', 'Uspešno ste se odjavili.');
    }
}

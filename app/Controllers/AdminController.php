<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Category;
use App\Models\Log;
use App\Models\SupportTicket;
use App\Models\User;
use DomainException;

final class AdminController extends Controller
{
    public function index(): void
    {
        $this->auth->requireRole('admin');
        $userModel = new User($this->db);
        $logModel = new Log($this->db);
        $allUsers = $userModel->all();
        $userPagination = $userModel->page($this->pageNumber('users_page'));
        $users = $userPagination['items'];
        $managers = $userModel->activeManagers();
        $assignmentPagination = $userModel->assignmentsPage($this->pageNumber('assignments_page'));
        $assignments = $assignmentPagination['items'];
        $categoryPagination = (new Category($this->db))->page($this->pageNumber('categories_page'));
        $categories = $categoryPagination['items'];
        $ticketPagination = (new SupportTicket($this->db))->page($this->pageNumber('tickets_page'));
        $tickets = $ticketPagination['items'];
        $activityPagination = $logModel->activitiesPage(null, $this->pageNumber('activities_page'));
        $activities = $activityPagination['items'];
        $this->view->render('admin/index.twig', compact(
            'users', 'allUsers', 'managers', 'assignments', 'categories', 'tickets', 'activities',
            'userPagination', 'assignmentPagination', 'categoryPagination', 'ticketPagination',
            'activityPagination'
        ));
    }

    public function updateUser(int $userId): void
    {
        $this->auth->requireRole('admin');
        $this->validateCsrf();
        try {
            (new User($this->db))->updateAccess(
                $userId,
                $_POST['role'] ?? 'user',
                isset($_POST['active']),
                $this->auth->id()
            );
        } catch (DomainException $e) {
            $this->redirect('/admin', null, $e->getMessage());
        }
        $this->auth->log($this->auth->id(), 'Izmenjen korisnik #' . $userId);
        $this->redirect('/admin', 'Korisnik je izmenjen.');
    }

    public function assignManager(): void
    {
        $this->auth->requireRole('admin');
        $this->validateCsrf();
        try {
            (new User($this->db))->assignManager(
                (int) ($_POST['manager_id'] ?? 0),
                (int) ($_POST['user_id'] ?? 0)
            );
        } catch (DomainException $e) {
            $this->redirect('/admin', null, $e->getMessage());
        }
        $this->auth->log($this->auth->id(), 'Dodeljen menadžer #' . (int) ($_POST['manager_id'] ?? 0) . ' korisniku #' . (int) ($_POST['user_id'] ?? 0));
        $this->redirect('/admin', 'Menadžer je dodeljen.');
    }

    public function deleteAssignment(int $id): void
    {
        $this->auth->requireRole('admin');
        $this->validateCsrf();
        (new User($this->db))->deleteAssignment($id);
        $this->auth->log($this->auth->id(), 'Uklonjena dodela menadžera #' . $id);
        $this->redirect('/admin', 'Dodela je uklonjena.');
    }

    public function addCategory(): void
    {
        $this->auth->requireRole('admin');
        $this->validateCsrf();
        try {
            (new Category($this->db))->create($_POST['name'] ?? '', $_POST['type'] ?? 'expense');
        } catch (DomainException $e) {
            $this->redirect('/admin', null, $e->getMessage());
        }
        $this->auth->log($this->auth->id(), 'Dodata kategorija.');
        $this->redirect('/admin', 'Kategorija je dodata.');
    }

    public function toggleCategory(int $id): void
    {
        $this->auth->requireRole('admin');
        $this->validateCsrf();
        (new Category($this->db))->toggle($id);
        $this->auth->log($this->auth->id(), 'Promenjen status kategorije #' . $id);
        $this->redirect('/admin', 'Status kategorije je promenjen.');
    }

    public function ticketStatus(int $id): void
    {
        $this->auth->requireRole('admin');
        $this->validateCsrf();
        try {
            (new SupportTicket($this->db))->updateStatus($id, $_POST['status'] ?? 'open');
        } catch (DomainException $e) {
            $this->redirect('/admin', null, $e->getMessage());
        }
        $this->auth->log($this->auth->id(), 'Promenjen status zahteva #' . $id . ': ' . ($_POST['status'] ?? 'open'));
        $this->redirect('/admin', 'Status zahteva je promenjen.');
    }
}

<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Log;
use App\Models\SupportTicket;
use App\Models\User;
use DomainException;

final class ManagerController extends Controller
{
    public function index(): void
    {
        $this->auth->requireRole('manager');
        $userPagination = (new User($this->db))->assignedPage($this->auth->id(), $this->pageNumber());
        $users = $userPagination['items'];
        $this->view->render('manager/index.twig', compact('users', 'userPagination'));
    }

    public function user(int $userId): void
    {
        $this->auth->requireRole('manager');
        $user = $this->assignedUser($userId);
        $accounts = (new Account($this->db))->forUser($userId);
        $budgetPagination = (new Budget($this->db))->pageForUser($userId, $this->pageNumber('budgets_page'));
        $budgets = $budgetPagination['items'];
        $transactionPagination = (new Account($this->db))->transactionPage($userId, $this->pageNumber('transactions_page'));
        $transactions = $transactionPagination['items'];
        $activityPagination = (new Log($this->db))->activitiesPage($userId, $this->pageNumber('activities_page'));
        $activities = $activityPagination['items'];
        $ticketPagination = (new SupportTicket($this->db))->pageForUser($userId, $this->pageNumber('tickets_page'));
        $tickets = $ticketPagination['items'];
        $categories = (new Category($this->db))->active('expense');
        $this->view->render('manager/user.twig', compact(
            'user', 'accounts', 'budgets', 'transactions', 'activities', 'categories', 'tickets',
            'budgetPagination', 'transactionPagination', 'activityPagination', 'ticketPagination'
        ));
    }

    public function saveBudget(int $userId): void
    {
        $this->auth->requireRole('manager');
        $this->validateCsrf();
        $this->assignedUser($userId);
        try {
            (new Budget($this->db))->save(
                $userId,
                (int) ($_POST['category_id'] ?? 0),
                (float) ($_POST['amount'] ?? 0),
                (int) ($_POST['month'] ?? 0),
                (int) ($_POST['year'] ?? 0),
                $this->auth->id()
            );
        } catch (DomainException $e) {
            $this->redirect('/manager/users/' . $userId, null, $e->getMessage());
        }
        $this->auth->log($this->auth->id(), 'Sačuvan budžet korisnika #' . $userId);
        $this->redirect('/manager/users/' . $userId, 'Budžet je sačuvan.');
    }

    public function deleteBudget(int $userId, int $budgetId): void
    {
        $this->auth->requireRole('manager');
        $this->validateCsrf();
        $this->assignedUser($userId);
        (new Budget($this->db))->delete($userId, $budgetId);
        $this->auth->log($this->auth->id(), 'Obrisan budžet #' . $budgetId . ' korisnika #' . $userId);
        $this->redirect('/manager/users/' . $userId, 'Budžet je obrisan.');
    }

    public function replySupport(int $userId, int $ticketId): void
    {
        $this->auth->requireRole('manager');
        $this->validateCsrf();
        $this->assignedUser($userId);
        $path = '/manager/users/' . $userId . '?tickets_page=' . $this->pageNumber('tickets_page') . '#tickets';
        $reply = is_string($_POST['reply'] ?? null) ? $_POST['reply'] : '';
        try {
            (new SupportTicket($this->db))->reply($ticketId, $userId, $this->auth->id(), $reply);
        } catch (DomainException $e) {
            $this->redirect($path, null, $e->getMessage());
        }
        $this->auth->log($this->auth->id(), 'Odgovoreno na zahtev za podršku #' . $ticketId);
        $this->redirect($path, 'Odgovor je sačuvan.');
    }

    private function assignedUser(int $userId): array
    {
        $user = (new User($this->db))->findAssigned($userId, $this->auth->id());
        if (!$user) {
            http_response_code(403);
            exit('Korisnik vam nije dodeljen.');
        }
        return $user;
    }
}

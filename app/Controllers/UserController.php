<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\SupportTicket;
use App\Services\TransactionService;
use DomainException;
use Throwable;

final class UserController extends Controller
{
    public function dashboard(): void
    {
        $this->auth->requireRole('user');
        $userId = $this->auth->id();

        $accountModel = new Account($this->db);
        $accounts = $accountModel->forUser($userId);
        $transactionPagination = $accountModel->transactionPage($userId, $this->pageNumber('transactions_page'));
        $transactions = $transactionPagination['items'];
        $budgets = (new Budget($this->db))->currentForUser($userId);
        $categories = (new Category($this->db))->active();
        $ticketPagination = (new SupportTicket($this->db))->pageForUser($userId, $this->pageNumber('tickets_page'));
        $tickets = $ticketPagination['items'];

        $this->view->render('user/dashboard.twig', compact(
            'accounts', 'transactions', 'budgets', 'categories', 'tickets', 'transactionPagination', 'ticketPagination'
        ));
    }

    public function addAccount(): void
    {
        $this->auth->requireRole('user');
        $this->validateCsrf();
        $name = trim($_POST['name'] ?? '');
        try {
            (new Account($this->db))->create($this->auth->id(), $name);
        } catch (DomainException $e) {
            $this->redirect('/dashboard', null, $e->getMessage());
        }
        $this->auth->log($this->auth->id(), 'Dodat račun: ' . $name);
        $this->redirect('/dashboard', 'Račun je dodat.');
    }

    public function moneyAction(): void
    {
        $this->auth->requireRole('user');
        $this->validateCsrf();
        $service = new TransactionService($this->db);
        $type = $_POST['type'] ?? '';

        try {
            if ($type === 'deposit') {
                $service->deposit($this->auth->id(), (int) $_POST['account_id'], (float) $_POST['amount'], (int) ($_POST['category_id'] ?? 0));
                $action = 'Uplata';
            } elseif ($type === 'withdrawal') {
                $service->withdraw($this->auth->id(), (int) $_POST['account_id'], (float) $_POST['amount'], (int) ($_POST['category_id'] ?? 0));
                $action = 'Isplata';
            } else {
                throw new \InvalidArgumentException('Nepoznata operacija.');
            }
            $this->auth->log($this->auth->id(), $action . ': ' . (float) $_POST['amount'] . ' RSD');
            $this->redirect('/dashboard', $action . ' je uspešno evidentirana.');
        } catch (Throwable $e) {
            $this->redirect('/dashboard', null, $e->getMessage());
        }
    }

    public function transfer(): void
    {
        $this->auth->requireRole('user');
        $this->validateCsrf();
        try {
            (new TransactionService($this->db))->transfer(
                $this->auth->id(),
                (int) $_POST['source_id'],
                (int) $_POST['destination_id'],
                (float) $_POST['amount']
            );
            $this->auth->log($this->auth->id(), 'Transfer: ' . (float) $_POST['amount'] . ' RSD');
            $this->redirect('/dashboard', 'Transfer je uspešno izvršen.');
        } catch (Throwable $e) {
            $this->redirect('/dashboard', null, $e->getMessage());
        }
    }

    public function support(): void
    {
        $this->auth->requireRole('user');
        $this->validateCsrf();
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        try {
            (new SupportTicket($this->db))->create($this->auth->id(), $subject, $message);
        } catch (DomainException $e) {
            $this->redirect('/dashboard', null, $e->getMessage());
        }
        $this->auth->log($this->auth->id(), 'Poslat zahtev za podršku.');
        $this->redirect('/dashboard#tickets', 'Poruka podršci je poslata.');
    }
}

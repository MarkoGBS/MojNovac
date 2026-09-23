<?php

namespace App\Controllers;

use App\Core\Controller;

final class HomeController extends Controller
{
    public function index(): void
    {
        $this->view->render('home.twig');
    }

    public function faq(): void
    {
        $this->view->render('faq.twig');
    }
}

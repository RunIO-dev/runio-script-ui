<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserProviderInterface;

use function Runio\ScriptUi\respond;

final class ToolsController extends AbstractController
{
    /**
     * A login for the tests, without a form: the user comes from the list in
     * config/packages/security.yaml and is logged in by Symfony itself.
     */
    #[Route('/login-as/{name}')]
    public function login(string $name, Security $security, UserProviderInterface $users): Response
    {
        $security->login($users->loadUserByIdentifier($name));

        return $this->redirect('/admin/tools');
    }

    /**
     * The tools, as the README shows it for Symfony.
     */
    #[Route('/admin/tools/{tool}', defaults: ['tool' => ''], methods: ['GET', 'POST'])]
    public function tools(Request $request): Response
    {
        $page = respond(
            $this->getParameter('kernel.project_dir') . '/tools',
            base: '/admin/tools',
            auth: fn () => $this->getUser(),
            allow: fn ($user, string $tool): bool => $tool !== 'payroll' || $this->isGranted('ROLE_PAYROLL'),
            variables: ['app' => 'Symfony ' . Kernel::VERSION],
            session: $request->getSession(),
        );

        return new Response($page->body, $page->status, $page->headers);
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\EventSubscriber\AutoRcaSubscriber;
use App\Upsun\UpsunClientFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/auto-rca')]
#[IsGranted(User::ROLE_ADMIN)]
final class AutoRcaController extends AbstractController
{
    /**
     * Shows the Auto-RCA test panel.
     */
    #[Route('', name: 'admin_auto_rca', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/auto_rca/index.html.twig');
    }

    /**
     * Throws a RuntimeException so KernelEvents::EXCEPTION fires and the
     * AutoRcaSubscriber handles it exactly as it would in production.
     *
     * To actually spawn the task container, set PLATFORM_ENVIRONMENT_TYPE=production
     * in your .env.local (Guard 1 of AutoRcaSubscriber).
     */
    #[Route('/simulate-exception', name: 'admin_auto_rca_simulate', methods: ['POST'])]
    public function simulateException(Request $request): never
    {
        if (!$this->isCsrfTokenValid('auto_rca_simulate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        throw new \RuntimeException('[Auto-RCA test] Simulated 500 error triggered from the admin panel.');
    }

    /**
     * Directly spawns the RCA task container, bypassing the AutoRcaSubscriber.
     * Useful when PLATFORM_ENVIRONMENT_TYPE is not set to production locally.
     */
    #[Route('/trigger', name: 'admin_auto_rca_trigger', methods: ['POST'])]
    public function triggerDirectly(Request $request, UpsunClientFactory $factory): Response
    {
        if (!$this->isCsrfTokenValid('auto_rca_trigger', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $fakeSignature = hash('sha256', 'admin-test-' . time());

        $incident = [
            'signature' => $fakeSignature,
            'exception' => [
                'class' => \RuntimeException::class,
                'message' => '[Auto-RCA test] Manually triggered from admin panel.',
                'file' => __FILE__,
                'line' => __LINE__,
                'trace_top5' => [],
            ],
            'request' => [
                'method' => $request->getMethod(),
                'route' => 'admin_auto_rca_trigger',
                'path' => $request->getPathInfo(),
                'user_agent' => substr($request->headers->get('User-Agent', ''), 0, 200),
            ],
            'triggered_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'test' => true,
        ];

        try {
            $projectId = $_ENV['PLATFORM_PROJECT'] ?? $_SERVER['PLATFORM_PROJECT'] ?? '';
            $environmentId = $_ENV['PLATFORM_ENVIRONMENT'] ?? $_SERVER['PLATFORM_ENVIRONMENT'] ?? 'main';
            $taskId = $_ENV['UPSUN_RCA_TASK_ID'] ?? $_SERVER['UPSUN_RCA_TASK_ID'] ?? 'opencode-rca';

            $factory->create()->tasksContainer->run(
                projectId: $projectId,
                environmentId: $environmentId,
                taskId: $taskId,
                variables: [
                    'INCIDENT_JSON' => ['value' => json_encode($incident, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)],
                    'INCIDENT_SIGNATURE' => ['value' => $fakeSignature],
                ],
            );

            $this->addFlash('success', sprintf(
                'Task container "%s" spawned on environment "%s" (project %s). Signature: %s',
                $taskId, $environmentId, $projectId, substr($fakeSignature, 0, 12) . '…'
            ));
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Failed to spawn task container: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_auto_rca');
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Github\GitHubAppTokenMinter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Upsun\Api\ApiException;
use Upsun\UpsunClient;

#[Route('/admin/auto-rca')]
#[IsGranted(User::ROLE_ADMIN)]
final class AutoRcaController extends AbstractController
{
    #[Route('', name: 'admin_auto_rca', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/auto_rca/index.html.twig');
    }

    #[Route('/simulate-exception', name: 'admin_auto_rca_simulate', methods: ['POST'])]
    public function simulateException(Request $request): never
    {
        $this->validateCsrf('auto_rca_simulate', $request);

        // Include a unique token so each click yields a distinct exception
        // signature; otherwise the AutoRcaSubscriber dedupe (done/attempts cache)
        // would suppress every trigger after the first one. The leading letter
        // keeps the token from being collapsed by the signature's number
        // normalisation (\b\d+\b -> N).
        $token = 'r'.bin2hex(random_bytes(5));

        throw new \RuntimeException(sprintf(
            '[Auto-RCA test] Simulated 500 error triggered from the admin panel (ref %s).',
            $token,
        ));
    }

    #[Route('/trigger', name: 'admin_auto_rca_trigger', methods: ['POST'])]
    public function triggerDirectly(Request $request, UpsunClient $upsunClient, GitHubAppTokenMinter $tokenMinter): RedirectResponse
    {
        $this->validateCsrf('auto_rca_trigger', $request);

        $projectId     = $this->resolveEnv('PLATFORM_PROJECT');
        $environmentId = $this->resolveEnv('PLATFORM_BRANCH', 'main');
        $taskId        = $this->resolveEnv('UPSUN_RCA_TASK_ID', 'opencode-rca');

        try {
            $upsunClient->taskContainers->run(
                projectId: $projectId,
                environmentId: $environmentId,
                taskId: $taskId,
                variables: $this->buildIncidentVariables($request, $tokenMinter),
            );

            $this->addFlash('success', sprintf(
                'Task container "%s" spawned on "%s" (project %s).',
                $taskId,
                $environmentId,
                $projectId !== '' ? $projectId : '<missing PLATFORM_PROJECT>',
            ));
        } catch (ApiException $e) {
            $this->addFlash('danger', sprintf(
                'Upsun API rejected the task run (HTTP %d%s): %s — body: %s',
                $e->getCode(),
                $e->getApiTitle() !== null ? ' '.$e->getApiTitle() : '',
                $e->getApiMessage() ?? $e->getMessage(),
                (string) $e->getResponseBody(),
            ));
        } catch (\Throwable $e) {
            $this->addFlash('danger', sprintf(
                'Failed to spawn task container [%s]: %s',
                $e::class,
                $e->getMessage(),
            ));
        }

        return $this->redirectToRoute('admin_auto_rca');
    }

    private function validateCsrf(string $intention, Request $request): void
    {
        if (!$this->isCsrfTokenValid($intention, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function resolveEnv(string $name, string $default = ''): string
    {
        return getenv($name) ?: $default;
    }

    private function buildIncidentVariables(Request $request, GitHubAppTokenMinter $tokenMinter): array
    {
        $signature = hash('sha256', 'admin-test-'.time());

        $incident = [
            'signature'    => $signature,
            'exception'    => [
                'class'      => \RuntimeException::class,
                'message'    => '[Auto-RCA test] Manually triggered from admin panel.',
                'file'       => __FILE__,
                'line'       => __LINE__,
                'trace_top5' => [],
            ],
            'request'      => [
                'method'     => $request->getMethod(),
                'route'      => 'admin_auto_rca_trigger',
                'path'       => $request->getPathInfo(),
                'user_agent' => substr((string) $request->headers->get('User-Agent', ''), 0, 200),
            ],
            'triggered_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'test'         => true,
        ];

        $env = [
            'INCIDENT_JSON'      => json_encode($incident, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            'INCIDENT_SIGNATURE' => $signature,
        ];

        // Hand the task a short-lived, repo-scoped GitHub token so OpenCode can
        // open a pull request. If minting is not configured/fails, the task
        // still runs and simply skips the PR step.
        $github = $tokenMinter->mintInstallationToken();
        if ($github !== null) {
            // Use a custom name (NOT GITHUB_TOKEN/GH_TOKEN): OpenCode's
            // github-copilot LLM provider authenticates with GITHUB_TOKEN, and a
            // GitHub App server-to-server token is rejected by that endpoint.
            $env['GH_PR_TOKEN'] = $github['token'];
            $env['GITHUB_REPO'] = $github['repository'];
        }

        return ['env' => $env];
    }
}
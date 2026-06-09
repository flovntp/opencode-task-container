<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Admin;

use App\Entity\User;
use App\Github\GitHubAppTokenMinter;
use App\Upsun\UpsunClientFactory;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Upsun\Api\ApiException;

#[Route('/admin/auto-rca')]
#[IsGranted(User::ROLE_ADMIN)]
final class AutoRcaController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'admin_auto_rca', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/auto_rca/index.html.twig');
    }

    #[Route('/simulate-exception', name: 'admin_auto_rca_simulate', methods: ['POST'])]
    public function simulateException(Request $request): never
    {
        $this->validateCsrf('auto_rca_simulate', $request);

        throw new \RuntimeException('[Auto-RCA test] Simulated 500 error triggered from the admin panel.');
    }

    /**
     * Directly spawns an Upsun task container with a synthetic incident payload.
     *
     * This is a test/debug endpoint — it does NOT wait for a real exception.
     * Instead, it constructs a fake incident payload (see buildIncidentVariables)
     * and sends it to the Upsun task container API immediately. This allows
     * developers to exercise the full Auto-RCA pipeline (task container, OpenCode,
     * PR creation) without having to reproduce a production error.
     *
     * The RuntimeException class, message, file, and line in the payload are all
     * hand-crafted. The "line" is the __LINE__ constant within buildIncidentVariables.
     */
    #[Route('/trigger', name: 'admin_auto_rca_trigger', methods: ['POST'])]
    public function triggerDirectly(Request $request, UpsunClientFactory $factory, GitHubAppTokenMinter $tokenMinter): RedirectResponse
    {
        $this->validateCsrf('auto_rca_trigger', $request);

        $projectId = $this->resolveEnv('PLATFORM_PROJECT');
        $environmentId = $this->resolveEnv('PLATFORM_BRANCH', 'main');
        $taskId = $this->resolveEnv('UPSUN_RCA_TASK_ID', 'opencode-rca');

        $this->logger->info('Auto-RCA: manual trigger invoked.', [
            'project' => $projectId,
            'environment' => $environmentId,
            'task' => $taskId,
        ]);

        try {
            $factory->create()->taskContainers->run(
                projectId: $projectId,
                environmentId: $environmentId,
                taskId: $taskId,
                variables: $this->buildIncidentVariables($request, $tokenMinter),
            );

            $this->addFlash('success', \sprintf(
                'Task container "%s" spawned on "%s" (project %s).',
                $taskId,
                $environmentId,
                '' !== $projectId ? $projectId : '<missing PLATFORM_PROJECT>',
            ));
        } catch (ApiException $e) {
            $this->addFlash('danger', \sprintf(
                'Upsun API rejected the task run (HTTP %d%s): %s — body: %s',
                $e->getCode(),
                null !== $e->getApiTitle() ? ' '.$e->getApiTitle() : '',
                $e->getApiMessage() ?? $e->getMessage(),
                (string) $e->getResponseBody(),
            ));
        } catch (\Throwable $e) {
            $this->addFlash('danger', \sprintf(
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

    /**
     * Builds a synthetic incident payload for the manual test trigger.
     *
     * All fields are hand-crafted — the RuntimeException, its message, file,
     * and line are not the result of a real exception. The line number is the
     * __LINE__ constant evaluated at the point where the "line" key is set.
     * The "trace_top5" is intentionally empty because no real stack trace exists.
     */
    private function buildIncidentVariables(Request $request, GitHubAppTokenMinter $tokenMinter): array
    {
        $signature = hash('sha256', 'admin-test-'.time());

        $incident = [
            'signature' => $signature,
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
                'user_agent' => substr((string) $request->headers->get('User-Agent', ''), 0, 200),
            ],
            'triggered_at' => new \DateTimeImmutable()->format(\DateTimeInterface::ATOM),
            'test' => true,
        ];

        $env = [
            'INCIDENT_JSON' => json_encode($incident, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            'INCIDENT_SIGNATURE' => $signature,
        ];

        // Hand the task a short-lived, repo-scoped GitHub token so OpenCode can
        // open a pull request. If minting is not configured/fails, the task
        // still runs and simply skips the PR step.
        $github = $tokenMinter->mintInstallationToken();
        if (null !== $github) {
            // Use a custom name (NOT GITHUB_TOKEN/GH_TOKEN): OpenCode's
            // github-copilot LLM provider authenticates with GITHUB_TOKEN, and a
            // GitHub App server-to-server token is rejected by that endpoint.
            $env['GH_PR_TOKEN'] = $github['token'];
            $env['GITHUB_REPO'] = $github['repository'];
        }

        return ['env' => $env];
    }
}

<?php

namespace Modules\DeployEnv\tests\Feature;

use Illuminate\Support\Facades\Log;
use Modules\SystemBase\app\Services\GitService;
use Modules\SystemBase\tests\TestCase;

class GitTest extends TestCase
{
    const gitTestPath = 'tests/git_pulls';

    /**
     * Testing results of
     * GitService::findSatisfiedVersion()
     * GitService::findSatisfiedBranch()
     */
    public function test_constraint_satisfied_versions(): void
    {
        // $testList = [
        //     [
        //         'test'   => '*',
        //         'type'   => 'version',
        //         'list'   => ['v1.0.0', 'v1.2.0', 'v1.0.0', 'v2.0.0', 'v5.0.1'],
        //         'expect' => 'v5.0.1',
        //     ],
        //     [
        //         'test'   => '^1.1.0',
        //         'type'   => 'version',
        //         'list'   => ['v1.0.0', 'v1.2.0', 'v1.0.0', 'v2.0.0', 'v5.0.1'],
        //         'expect' => 'v1.2.0',
        //     ],
        //     [
        //         'test'   => '^1.1.0',
        //         'type'   => 'version',
        //         'list'   => ['v1.0.0', 'v1.1.3', 'v1.2.0', 'v1.0.0', 'v2.0.0', 'v5.0.1'],
        //         'expect' => 'v1.2.0',
        //     ],
        //     [
        //         'test'   => '^1.0',
        //         'type'   => 'version',
        //         'list'   => ['v1.0.0', 'v1.2.0', 'v1.0.0', 'v2.0.0', 'v5.0.1'],
        //         'expect' => 'v1.2.0',
        //     ],
        //     [
        //         'test'   => 'dev-master',
        //         'type'   => 'branch',
        //         'list'   => ['v1.0.0', 'v1.2.0', 'master', 'test_branch', 'v1.0.0', 'v2.0.0', 'v5.0.1'],
        //         'expect' => 'master',
        //     ],
        // ];

        /** @var GitService $gitService */
        $gitService = app(GitService::class);
        // foreach ($testList as $tesItem) {
        //     $constraint = data_get($tesItem, 'test');
        //     $type = data_get($tesItem, 'type');
        //     $expected = data_get($tesItem, 'expect');
        //
        //     switch ($type) {
        //         case 'version':
        //             $result = $gitService->findSatisfiedVersion(data_get($tesItem, 'list'), $constraint);
        //             break;
        //         case 'branch':
        //             $result = $gitService->findSatisfiedBranch(data_get($tesItem, 'list'), $constraint);
        //             break;
        //     }
        //
        //     if ($result !== $expected) {
        //         $this->assertTrue(false,
        //             sprintf("Result for '%s': '%s', but expected was '%s'.", $constraint, $result, $expected));
        //     }
        // }

        // prepare the pull
        $testRepoFilePath = 'test1'; // uniqid();
        $testRepoFilePath = storage_path(self::gitTestPath.DIRECTORY_SEPARATOR.$testRepoFilePath);
        $gitSourceCurrent = 'https://github.com/aklebe-laravel/test-github-actions.git';
        if (!$gitService->ensureRepository($testRepoFilePath, $gitSourceCurrent, false)) {
            $this->assertTrue(false, sprintf("Repository failed: %s to %s", $gitSourceCurrent, $testRepoFilePath));
        }

        // Git checkout part:
        // If no constraint branch or version is defined in config,
        // then no checkout() will performed!
        $configRequiredConstraint = '^v0.2';

        if ($checkoutName = $gitService->findBestTagOrBranch($configRequiredConstraint)) {
            Log::debug(sprintf("Checkout '%s' ...", $checkoutName));
            if (!$gitService->repositoryCheckout($checkoutName)) {
                $this->assertTrue(false, sprintf("Failed to checkout: %s", $checkoutName));
            }
        } else {
            $this->assertTrue(false, sprintf("Nothing matched to checkout with: %s", $configRequiredConstraint));
        }


        $this->assertTrue(true);
    }
}

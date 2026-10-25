<?php

namespace Modules\DeployEnv\app\Services\Base;

use CzProject\GitPhp\GitException;
use Exception;
use Illuminate\Support\Collection;
use Modules\SystemBase\app\Services\Base\AddonObjectService;
use Modules\SystemBase\app\Services\Base\BaseService;
use Modules\SystemBase\app\Services\GitService;
use Modules\SystemBase\app\Services\ParserService;

/**
 * Base class for RequireModuleService and RequireThemeService:
 * clones/updates the git repository of a module or theme.
 */
class RequireBaseService extends BaseService
{
    /**
     * Overwrite this!
     * Currently one of them: 'module', 'theme'
     *
     * @var string
     */
    protected string $addonType = '';

    /**
     * this should be injected by delivered class.
     *
     * @var AddonObjectService|null
     */
    protected ?AddonObjectService $addonObjectService = null;

    /**
     * Git url, can contain placeholders like "git@my-git-server:${{module_vendor_name}}/${{module_snake_name_git}}.git".
     * Available: module_vendor_name, module_snake_name, module_snake_name_git and the same with theme_ prefix.
     *
     * @var string
     */
    public string $repositorySource = '';

    /**
     * Composer like constraint for the tag/branch to checkout, like 'dev-master' or '^1.0'
     *
     * @var string
     */
    public string $currentRequiredConstraint = 'dev-master';

    /**
     * @var string
     */
    protected string $currentVendorName = '';

    /**
     * @var string
     */
    protected string $currentSnakeName = '';

    /**
     * @var string
     */
    protected string $currentStudlyName = '';

    /**
     * name.'-module' or name.'-theme'
     *
     * @var string
     */
    protected string $currentNameGit = '';

    /**
     * Snake names already processed in this run (to process every item only once).
     *
     * @var array
     */
    protected array $alreadyProcessed = [];

    /**
     * Switches, see allowProcess().
     *
     * @var array
     */
    protected array $allowedProcesses = [
        'git_repository'     => true, // false to generally prevent git processes
        'git_pull'           => true, // false to prevent git pull
        'set_status'         => true, // false do prevent update modules_statuses.json
        'dev_mode'           => true, // true to allow ignoring changes (if not clean) repositories and continue processing
        'is_bare_deployment' => false, // use "git archive" to fast deploy without the ".git" directory (ex.: for production)
    ];

    /**
     * List of changed repositories after pull
     *
     * @var array
     */
    public array $changedRepositories = [];

    /**
     * Sets the vendor (also for the addon service).
     *
     * @param  string  $vendorName
     *
     * @return bool  false if the vendor is empty
     */
    public function setVendor(string $vendorName): bool
    {
        $this->currentVendorName = $vendorName;
        $this->addonObjectService->vendor = $vendorName;

        return $vendorName !== '';
    }

    /**
     * @return string
     */
    public function getVendor(): string
    {
        return $this->currentVendorName;
    }

    /**
     * Sets a switch of $allowedProcesses like 'git_pull' or 'dev_mode'.
     *
     * @param  string  $process
     * @param  bool    $allow
     *
     * @return void
     */
    public function allowProcess(string $process, bool $allow = true): void
    {
        $this->allowedProcesses[$process] = $allow;
    }

    /**
     * Parser with the placeholders for $repositorySource.
     *
     * @return ParserService
     */
    protected function getParser(): ParserService
    {
        /** @var ParserService $parser */
        $parser = app(ParserService::class);

        $placeHolders = [
            'module_vendor_name'    => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) {
                    return $this->currentVendorName;
                },
            ],
            'module_snake_name'     => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) {
                    return $this->currentSnakeName;
                },
            ],
            'module_snake_name_git' => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) {
                    return $this->currentNameGit;
                },
            ],
            'theme_vendor_name'     => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) {
                    return $this->currentVendorName;
                },
            ],
            'theme_snake_name'      => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) {
                    return $this->currentSnakeName;
                },
            ],
            'theme_snake_name_git'  => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) {
                    return $this->currentNameGit;
                },
            ],
        ];
        $parser->setPlaceholders($placeHolders);

        return $parser;
    }

    /**
     * Requires an item by its info data (see AddonObjectService::getItemInfo()).
     *
     * @param  array  $itemInfoData
     *
     * @return bool
     * @throws GitException|Exception
     */
    public function requireItemByData(array $itemInfoData): bool
    {
        return $this->requireAddonTypeByData($itemInfoData);
    }

    /**
     * Requires all items, stops at the first failure.
     *
     * @param  Collection  $listOfDataObjects
     *
     * @return bool
     * @throws GitException
     */
    public function requireItemByListOfData(Collection $listOfDataObjects): bool
    {
        foreach ($listOfDataObjects as $item) {
            if (!$this->requireItemByData($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Use this method only as first level to require anything.
     *
     * You should run composer update after success!
     *
     * @param  string  $itemName
     * @param  string  $destinationRootPath  if set use this as project root (to deploy to external projects)
     *
     * @return bool
     * @throws GitException
     */
    public function requireItemByName(string $itemName, string $destinationRootPath = ''): bool
    {
        $this->changedRepositories = [];

        $itemsInfo = $this->addonObjectService->getItemInfo($itemName, $destinationRootPath);

        return $this->requireItemByData($itemsInfo);
    }

    /**
     * Clones or updates the repository of the item:
     * 1) clone if missing (with --depth=1 for bare deployments)
     * 2) in dev mode: skip repositories with local changes, otherwise fail on changes
     * 3) bare deployment: delete '.git', otherwise checkout/pull by $currentRequiredConstraint and enable it
     * Changed repositories are collected in $changedRepositories.
     *
     * @param  array  $addonTypeInfoData
     *
     * @return bool
     * @throws Exception
     */
    public function requireAddonTypeByData(array $addonTypeInfoData): bool
    {
        if (!($configGitSource = $this->repositorySource)) {
            $this->error('Git source not defined!', [__METHOD__, $configGitSource]);

            return false;
        }

        // setup config data
        $this->setVendor(data_get($addonTypeInfoData, 'vendor_name', '')); // @todo: rly set this up?
        $this->currentSnakeName = data_get($addonTypeInfoData, $this->addonType.'_snake_name', '');
        $this->currentStudlyName = data_get($addonTypeInfoData, 'studly_name', '');
        $this->currentNameGit = data_get($addonTypeInfoData, $this->addonType.'_snake_name_git', '');
        $this->incrementIndent();

        // check this item was already processed
        if (isset($this->alreadyProcessed[$this->currentSnakeName])) {
            $this->decrementIndent();

            return true;
        }

        $parser = $this->getParser();
        /** @var GitService $gitService */
        $gitService = app(GitService::class);
        // mark as processed
        $this->alreadyProcessed[$this->currentSnakeName] = true;

        // validate git parameters
        if (!$this->currentVendorName || !$this->currentNameGit) {
            $this->error('Missing composer.json', [$this->currentVendorName, $this->currentNameGit, __METHOD__]);
            $this->decrementIndent();

            return false;
        }

        // is git process allowed?
        if (!data_get($this->allowedProcesses, 'git_repository', false)) {
            $this->decrementIndent();

            return true;
        }

        $devMode = data_get($this->allowedProcesses, 'dev_mode', false);
        $repoPath = data_get($addonTypeInfoData, 'path');

        $gitSourceCurrent = $parser->parse($configGitSource);
        $this->debug(sprintf("Git source: \"%s\"", $gitSourceCurrent));
        $this->debug(sprintf("Path destination: \"%s\"", $repoPath));

        $cloneParams = [];
        // if bare wanted, set --depth=1 to minimize ".git" we will delete at the end ...
        if ($isBareDeployment = data_get($this->allowedProcesses, 'is_bare_deployment', false)) {
            $cloneParams['--depth'] = 1;
        }

        // @todo: check git source exists on git server would be nice at this point (testRemote() not working)
        // Clone the repository if missing. Not in dev mode the repository has to be clean.
        if (!$gitService->ensureClonedRepository($repoPath, $gitSourceCurrent, !$devMode, $cloneParams)) {
            $this->decrementIndent();

            return false;
        }

        // In dev mode a repository with local changes is skipped (true = continue with the next item).
        if (!$isBareDeployment && $devMode && $gitService->hasChanges()) {
            $this->debug(sprintf("Repository for '%s' has changes. Skipping.", $this->currentStudlyName));
            $this->decrementIndent();

            return true;
        }

        // repository now exist and is clean ...
        if ($isBareDeployment) {

            // delete ".git" directory ...
            app('system_base_file')->deleteDir($repoPath.DIRECTORY_SEPARATOR.'.git');

        } else {

            // fetch, checkout and pull (see GitService::repositoryUpdate())
            $gitService->repositoryUpdate($this->currentRequiredConstraint, data_get($this->allowedProcesses, 'git_pull', false));

            $this->setStatus(true);
        }

        // new repository content?
        if ($gitService->repositoryJustUpdated) {
            $this->debug("Repository was updated or created!");
            $this->changedRepositories[] = $gitSourceCurrent;
        } else {
            $this->debug("Repository was already up-to-date!");
        }

        $this->decrementIndent();

        return true;
    }

    /**
     * Sets the status of the current item (if 'set_status' is allowed).
     * An existing status is not changed.
     *
     * @param  bool  $enabled
     *
     * @return void
     */
    protected function setStatus(bool $enabled): void
    {
        if (!data_get($this->allowedProcesses, 'set_status', false)) {
            return;
        }

        if (!$this->addonObjectService->setStatus($this->currentStudlyName, $enabled, true)) {
            $this->error(sprintf("Failed to set status of %s '%s'", $this->addonType, $this->currentStudlyName));
        }
    }

}
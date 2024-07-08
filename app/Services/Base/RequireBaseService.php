<?php

namespace Modules\DeployEnv\app\Services\Base;


use CzProject\GitPhp\GitException;
use Illuminate\Support\Collection;
use Modules\SystemBase\app\Services\Base\AddonObjectService;
use Modules\SystemBase\app\Services\Base\BaseService;
use Modules\SystemBase\app\Services\GitService;
use Modules\SystemBase\app\Services\ParserService;

/**
 * Base class for Modules and Themes -Services
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
     * List of modules already processed.
     *
     * @var array
     */
    protected array $alreadyProcessed = [];

    /**
     * @var array
     */
    protected array $allowedProcesses = [
        'git_repository' => true, // false to generally prevent git processes
        'git_pull'       => true, // false to prevent git pull
        'set_status'     => true, // false do prevent update modules_statuses.json
        'dev_mode'       => true, // true to allow ignoring changes (if not clean) repositories and continue processing
    ];

    /**
     * List of changed repositories after pull
     *
     * @var array
     */
    public array $changedRepositories = [];

    /**
     * @param  string  $process
     * @param  bool  $allow
     * @return void
     */
    public function allowProcess(string $process, bool $allow = true): void
    {
        $this->allowedProcesses[$process] = $allow;
    }

    /**
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
     * @param  array  $itemInfoData
     * @return bool
     * @throws GitException
     */
    public function requireItemByData(array $itemInfoData): bool
    {
        return $this->requireAddonTypeByData($itemInfoData);
    }

    /**
     * @param  Collection  $listOFDataObjects
     * @return bool
     * @throws GitException
     */
    public function requireItemByListOfData(Collection $listOFDataObjects): bool
    {
        foreach ($listOFDataObjects as $item) {
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
     * If $itemName is null, all items will be updated.
     *
     * @param  string|null  $itemName
     * @return bool
     * @throws GitException
     */
    public function requireItemByName(?string $itemName = null): bool
    {
        $this->changedRepositories = [];

        if ($itemName) {

            $itemsInfo = $this->addonObjectService->getItemInfo($itemName);
            return $this->requireItemByData($itemsInfo);

        } else {

            // require all items already installed
            $this->info(sprintf("Starting %s requirements/update from installed %ss ...", $this->addonType,
                $this->addonType));
            $itemsInfo = $this->addonObjectService->getItemInfoList(false); // false = get disable items too
            if (!$this->requireItemByListOfData($itemsInfo)) {
                return false;
            }

            // require all items defined in config
            $this->info(sprintf("Starting %s requirements from config 'mercy-dependencies.php' ...", $this->addonType));
            $itemsInfo = $this->getConfigRequirementItemInfoList();
            return $this->requireItemByListOfData($itemsInfo);

        }
    }

    /**
     * @param  array  $addonTypeInfoData
     * @return bool
     * @throws GitException
     */
    public function requireAddonTypeByData(array $addonTypeInfoData): bool
    {
        if (!($configGitSource = config('mercy-dependencies.required.git.source'))) {
            $this->error('Git source not defined!', [__METHOD__, $configGitSource]);
            return false;
        }

        // setup config data
        $this->currentVendorName = data_get($addonTypeInfoData, 'vendor_name', '');
        $this->currentSnakeName = data_get($addonTypeInfoData, $this->addonType.'_snake_name', '');
        $this->currentStudlyName = data_get($addonTypeInfoData, 'studly_name', '');
        $this->currentNameGit = data_get($addonTypeInfoData, $this->addonType.'_snake_name_git', '');
        // $this->debug("Require $this->addonType: ".$this->currentNameGit);
        $this->incrementIndent();

        // check this item was already processed
        if (isset($this->alreadyProcessed[$this->currentSnakeName])) {
            // $this->debug("$this->addonType already processed right now.");
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
            // $this->debug("Process git repository skipped.");
            $this->decrementIndent();
            return true;
        }

        // parse git url ...
        $gitSourceCurrent = $parser->parse($configGitSource);
        $this->debug(sprintf("Checking current git source: %s", $gitSourceCurrent));

        // @todo: check git source exists would be nice at this point ...
        //            // testRemote() not working
        //            $this->debug(sprintf("Checking GIT readable: %s", $gitSourceCurrent));
        //            if (!$gitService->testRemote($configGitSource)) {
        //                $this->error(sprintf("Unable to read git '%s'", $gitSourceCurrent));
        //                return false;
        //            }


        // Git update if theme/module folder exists, or create new repo.
        // Also ensure it's a clean repo without changes.
        $devMode = data_get($this->allowedProcesses, 'dev_mode', false);
        $repoPath = data_get($addonTypeInfoData, 'path');
        if (!$gitService->ensureRepository($repoPath, $gitSourceCurrent, !$devMode)) {
            $this->decrementIndent();
            return false;
        }

        // dev_mode && not clean
        if ($gitService->hasChanges()) {
            $this->debug(sprintf("Repository has changes, but dev_mode enabled. Skipping. (dev_mode: %s)", $devMode));
            $this->decrementIndent();
            return true;
        }

        // repository now exist and clean ...

        // If no constraint branch or version is defined in config,
        // then no checkout() will performed!
        $configRequiredConstraint = config('mercy-dependencies.required.git.'.$this->addonType.'s.'.$this->currentVendorName.'/'.$this->currentNameGit,
            '');

        // Git pull will not work in some cases (checked out tags)
        // Processing the following steps:
        // 1) git fetch
        // 2) git checkout
        // 3) git merge
        $gitService->repositoryFetchAndMerge($configRequiredConstraint,
            data_get($this->allowedProcesses, 'git_pull', false));

        // new repository content?
        if ($gitService->repositoryJustUpdated) {
            $this->debug("Repository was updated or created!");
            $this->changedRepositories[] = $gitSourceCurrent;
        } else {
            $this->debug("Repository was already up-to-date!");
        }

        //
        $this->setStatus(true);

        //
        $this->decrementIndent();
        return true;
    }

    /**
     * @return Collection
     */
    public function getConfigRequirementItemInfoList(): Collection
    {
        $itemsInfo = collect();
        // get the keys as name ...
        $itemNames = array_keys(config('mercy-dependencies.required.git.'.$this->addonType.'s', []));
        foreach ($itemNames as $itemName) {
            $itemsInfo->add($this->addonObjectService->getItemInfo($itemName));
        }

        return $itemsInfo;
    }

    /**
     *
     * @param  bool  $enabled
     * @return void
     */
    protected function setStatus(bool $enabled)
    {
        if (data_get($this->allowedProcesses, 'set_status', false)) {
            if (!$this->addonObjectService->setStatus($this->currentStudlyName, true, true)) {
                $this->error(sprintf("Failed to enable %s '%s'", $this->addonType, $this->currentStudlyName));
            } // no success message because no changes if module/theme already exists
        } else {
            // $this->debug("Process set status skipped.");
        }
    }

}
<?php
declare(strict_types = 1);

namespace Modules\DeployEnv\app\Services;

use Modules\SystemBase\app\Services\Base\BaseService;
use Modules\SystemBase\app\Services\GitService;
use Modules\SystemBase\app\Services\ModuleService;
use Modules\SystemBase\app\Services\ParserService;

/**
 * Creates a new module from the stubs in resources/module-stubs/ModuleTemplate.
 * Placeholders like ${{module_name}} in file names and content are replaced.
 */
class MakeModuleService extends BaseService
{
    /**
     * @var ModuleService|null
     */
    protected ?ModuleService $moduleService = null;

    /**
     * Additional placeholders for the stubs (see ParserService::setPlaceholders()).
     *
     * @var array
     */
    public array $additionalParserPlaceHolders = [];

    /**
     * @param  ModuleService  $moduleService
     */
    public function __construct(ModuleService $moduleService)
    {
        parent::__construct();

        $this->moduleService = $moduleService;
    }

    /**
     * Parser with the placeholders module_name, module_name_lower, module_author_name,
     * module_author_email and module_vendor_name (values from env MODULE_DEPLOYENV_MAKE_MODULE_*).
     *
     * @param  string  $moduleName
     *
     * @return ParserService
     */
    protected function getModuleParser(string $moduleName): ParserService
    {
        /** @var ParserService $parser */
        $parser = app(ParserService::class);

        $placeHolders = [
            'module_name'         => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) use (
                    $moduleName
                ) {
                    return $moduleName;
                },
            ],
            'module_name_lower'   => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) use (
                    $moduleName
                ) {
                    return $this->moduleService->getSnakeName($moduleName);
                },
            ],
            'module_author_name'  => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) use (
                    $moduleName
                ) {
                    return env('MODULE_DEPLOYENV_MAKE_MODULE_AUTHOR_NAME', 'John Doe');
                },
            ],
            'module_author_email' => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) use (
                    $moduleName
                ) {
                    return env('MODULE_DEPLOYENV_MAKE_MODULE_AUTHOR_EMAIL', 'john-doe@localhost.test');
                },
            ],
            'module_vendor_name'  => [
                'parameters' => [],
                'callback'   => function (array $placeholderParameters, array $parameters, array $recursiveData) use (
                    $moduleName
                ) {
                    return env('MODULE_DEPLOYENV_MAKE_MODULE_COMPOSER_VENDOR_NAME', 'my-vendor');
                },
            ],
            ... $this->additionalParserPlaceHolders,
        ];
        $parser->setPlaceholders($placeHolders);

        return $parser;
    }

    /**
     * Copies and parses all template files. Files ending with '.extToDelete' lose this extension.
     *
     * @param  string  $moduleName
     * @param  bool    $canUpdate            if false and the module directory exists, nothing happens
     * @param  string  $pathRootTemplate     default: the DeployEnv module stubs
     * @param  string  $pathRootDestination  if empty use module root directory
     * @param  bool    $overwrite            overwrite existing files (otherwise only missing files are created)
     *
     * @return bool
     * @throws \Exception
     */
    public function generateModuleFiles(
        string $moduleName,
        bool $canUpdate = false,
        string $pathRootTemplate = '',
        string $pathRootDestination = '',
        bool $overwrite = false
    ): bool {
        if (!$pathRootTemplate) {
            // default for make new module ...
            if (!($pathRootTemplate = ModuleService::getPath('module-stubs/ModuleTemplate',
                'DeployEnv',
                'resources'))
            ) {
                return false;
            }
        }

        $this->debug(sprintf("Generate files for module '%s' ...", $moduleName));

        if (!$pathRootDestination) {
            $pathRootDestination = $this->moduleService->getPath('', $moduleName);
            if (!$canUpdate && is_dir($pathRootDestination)) {
                $this->error(sprintf("Module directory already exists: %s", $pathRootDestination));

                return false;
            }
        }

        $parser = $this->getModuleParser($moduleName);
        $filesFound = 0;
        $filesUpdated = 0;

        app('system_base_file')->runDirectoryFiles($pathRootTemplate, function ($sourceFile, $sourcePathInfo) use (
            $moduleName,
            $pathRootTemplate,
            $pathRootDestination,
            $parser,
            &$filesFound,
            &$filesUpdated,
            $overwrite
        ) {
            $filesFound++;

            $subPath = app('system_base_file')->subPath($sourceFile, $pathRootTemplate);
            $pathInfo = pathinfo($subPath);

            // Calculate the basename (without extension .extToDelete if exists)
            $dirName = data_get($pathInfo, 'dirname');
            $ext = data_get($pathInfo, 'extension');
            if ($ext === 'extToDelete') {
                $baseName = data_get($pathInfo, 'filename');
            } else {
                $baseName = data_get($pathInfo, 'basename');
            }

            // Calculate the new path
            $fullDestinationPath = $pathRootDestination.$dirName;
            $fullDestinationPath = $fullDestinationPath.'/'.$baseName;

            // and parse the filename like ${{module_name}} itself
            $fullDestinationPath = $parser->parse($fullDestinationPath);

            // If file already exists we skip here ...
            if (!$overwrite && file_exists($fullDestinationPath)) {
                return true;
            }

            // copy the file (unparsed template), then parse its content
            if (!app('system_base_file')->copyPath($sourceFile, $fullDestinationPath)) {
                return false;
            }
            file_put_contents($fullDestinationPath, $parser->parse(file_get_contents($fullDestinationPath)));

            $this->debug(sprintf("Module %s: file created: %s", $moduleName, $fullDestinationPath));

            $filesUpdated++;

            return true;
        });

        $this->debug(sprintf("Files created/updated %s/%s", $filesUpdated, $filesFound));

        return true;
    }

    /**
     * Creates the module files, initializes git and enables the module.
     *
     * @param  string  $moduleName  any notation, it's converted to studly case
     * @param  bool    $canUpdate   create missing files of an existing module
     *
     * @return bool
     * @throws \Exception
     */
    public function makeModule(string $moduleName, bool $canUpdate = false): bool
    {
        // generate infos like studly name
        $moduleInfo = $this->moduleService->getItemInfo($moduleName);
        $moduleStudlyName = data_get($moduleInfo, 'studly_name');

        // generate the files ...
        if (!$this->generateModuleFiles($moduleStudlyName, $canUpdate)) {
            return false;
        }

        $moduleRootDir = $this->moduleService->getPath('', $moduleStudlyName);

        // init git
        /** @var GitService $gitService */
        $gitService = app(GitService::class);
        if (!is_dir($moduleRootDir.'/.git')) {
            if (!$gitService->repositoryInit($moduleRootDir)) {
                $this->error(sprintf("Unable to init git in %s", $moduleRootDir));
            }
        }

        // enable the module
        if (!$this->moduleService->setStatus($moduleStudlyName, true)) {
            $this->error(sprintf("Failed to enable module '%s'.", $moduleStudlyName));
        }

        return true;
    }
}
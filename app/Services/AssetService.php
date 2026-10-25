<?php

namespace Modules\DeployEnv\app\Services;

use Illuminate\Support\Str;
use Modules\SystemBase\app\Services\Base\BaseService;
use Modules\SystemBase\app\Services\ModuleService;
use Modules\SystemBase\app\Services\ThemeService;
use Nwidart\Modules\Module as NwidartModule;

/**
 * Builds the files storage/app/mercy-generated/{modules|themes}-import.{js|css|scss}
 * which import the assets of all enabled modules and of the active theme (inclusive parent themes).
 * The vite config includes these files.
 */
class AssetService extends BaseService
{
    /**
     * @var ModuleService|null
     */
    protected ?ModuleService $moduleService = null;

    /**
     * Path to generated asset files
     */
    const string STORAGE_PATH_MERCY_GENERATED = 'storage/app/mercy-generated';

    /**
     * Asset folder inside module resources like 'assets' or 'assets/{themer.framework}'.
     *
     * @var string
     */
    protected string $moduleAssetFrameworkPath = '';

    /**
     * Per addon type: path regex (to get the relative path), file regex per extension,
     * method 'include' (import statement) or 'merge' (copy content), 'reverse' order and 'last_only'.
     *
     * @var array
     */
    protected array $assetConfig = [];

    /**
     * @param  ModuleService  $moduleService
     */
    public function __construct(ModuleService $moduleService)
    {
        parent::__construct();
        $this->moduleService = $moduleService;

        $this->moduleAssetFrameworkPath = 'assets';
        if ($themeFramework = config('themer.framework', '')) {
            $this->moduleAssetFrameworkPath .= '/'.$themeFramework;
        }

        $this->assetConfig = [
            'modules' => [
                'path_regex' => '#^(.*\/Modules\/)(.+?)(\/.*)$#',
                'assets'     => [
                    'css' => [
                        'files'  => [
                            $this->moduleAssetFrameworkPath.'/css/.*\.css$',
                        ],
                        'method' => 'include', // include,merge
                    ],
                    'js'  => [
                        'files'  => [
                            $this->moduleAssetFrameworkPath.'/js/app\.js$',
                        ],
                        'method' => 'include', // include,merge
                    ],
                ],
            ],
            'themes'  => [
                'path_regex' => '#^(.*\/themes\/)(.+?)(\/.*)$#',
                'reverse'    => true,
                'assets'     => [
                    'css' => [
                        'files'  => [
                            'assets/css/.*\.css$',
                        ],
                        'method' => 'include', // include,merge
                    ],
                    'js'  => [
                        // last_only: default false, set true if you only need the inherited one instead of all found
                        'last_only' => true,
                        'files'     => [
                            'assets/js/app\.js$',
                        ],
                        'method'    => 'include',
                        // include,merge
                    ],
                ],
            ],
        ];

        // create folder if not exists ...
        $path = base_path(self::STORAGE_PATH_MERCY_GENERATED);
        if (!is_dir($path)) {
            app('system_base_file')->createDir($path);
        }
    }

    /**
     * Writes the import file per extension (always created, also if empty).
     *
     * @param  string  $addonType  'module' or 'theme'
     * @param  array   $fileListContainer  extension => list of full file paths
     *
     * @return void
     */
    public function implementFiles(string $addonType, array $fileListContainer): void
    {
        foreach ($fileListContainer as $extension => $fileList) {

            // "themes-import" or "modules-import"
            $destinationPath = base_path(self::STORAGE_PATH_MERCY_GENERATED.'/'.$addonType.'s-import.'.$extension);
            file_put_contents($destinationPath, '');

            if ((!is_array($fileList)) || (!$fileList)) {
                continue;
            }

            if (data_get($this->assetConfig, $addonType.'s.reverse', false)) {
                $fileList = array_reverse($fileList);
            }

            // if last_only, use the last item only ...
            if (data_get($this->assetConfig, $addonType.'s.assets.'.$extension.'.last_only', false)) {
                $fileList = [end($fileList)];
            }

            $method = data_get($this->assetConfig, $addonType.'s.assets.'.$extension.'.method', '');
            $pathRegex = data_get($this->assetConfig, $addonType.'s.path_regex');

            foreach ($fileList as $file) {
                if (file_exists($file)) {
                    if ($content = trim(file_get_contents($file))) {

                        // use regex to calculate the relative part of the given addon type (theme/module)
                        if (!preg_match($pathRegex, $file, $matches)) {
                            $this->warning('File '.$file.' does not match regular expression '.$pathRegex, [__METHOD__]);
                            continue;
                        }

                        // get the relative part only
                        $relativeFile = Str::after($file, $matches[1]);
                        // make string vite is able to resolve ('@themes/...')
                        $relativeFile = '@'.$addonType.'s/'.$relativeFile;

                        switch ($extension) {
                            case 'js':
                                file_put_contents($destinationPath, "/***** INCLUDE JS: '$file' *****/\n", FILE_APPEND);

                                if ($method === 'include') {
                                    file_put_contents($destinationPath, "import '$relativeFile';\n", FILE_APPEND);
                                } elseif ($method === 'merge') {
                                    file_put_contents($destinationPath, $content."\n\n", FILE_APPEND);
                                }
                                break;

                            case 'css':
                            case 'scss':
                            case 'sass':
                                file_put_contents($destinationPath, "/***** INCLUDE CSS: '$file' *****/\n", FILE_APPEND);

                                if ($method === 'include') {
                                    file_put_contents($destinationPath, "@import '$relativeFile';\n", FILE_APPEND);
                                } elseif ($method === 'merge') {
                                    file_put_contents($destinationPath, $content."\n\n", FILE_APPEND);
                                }
                                break;

                            case 'php':
                                file_put_contents($destinationPath, "/***** INCLUDE PHP: '$file' *****/\n", FILE_APPEND);

                                if ($method === 'include') {
                                    // @todo: @include or @use?
                                    file_put_contents($destinationPath, "include ('$relativeFile');\n", FILE_APPEND);
                                } elseif ($method === 'merge') {
                                    file_put_contents($destinationPath, $content."\n\n", FILE_APPEND);
                                }
                                break;
                        }

                    } // else no content
                }
            }
        }
    }

    /**
     * Preparing assets of enabled modules and theme(s) in storage/app/mercy-generated/
     *
     * @return void
     */
    public function buildMercyAssets(): void
    {
        $this->buildModuleAssets();
        $this->buildThemeAssets();
    }

    /**
     * Import files for the assets of all enabled modules.
     *
     * @return void
     */
    public function buildModuleAssets(): void
    {
        $required = [
            'js'   => [], // force to create xxx-import.js
            'css'  => [], // force to create xxx-import.css
            'scss' => [], // force to create xxx-import.scss
        ];
        $addonType = 'module';

        /** @var ModuleService $moduleService */
        $moduleService = app(ModuleService::class);
        $moduleService->runOrderedEnabledModules(function (?NwidartModule $module) use (
            $moduleService,
            &$required,
            $addonType
        ) {
            $assetPath = $moduleService->getPath('resources/'.$this->moduleAssetFrameworkPath, $module->getName());

            $this->getRequiredList($assetPath, $required, $addonType);

            return true;
        });

        $this->implementFiles($addonType, $required);
    }

    /**
     * Import files for the assets of the active theme and its parent themes.
     *
     * @return void
     * @todo: get from theme.json
     */
    public function buildThemeAssets(): void
    {
        $required = [
            'js'   => [], // force to create xxx-import.js
            'css'  => [], // force to create xxx-import.css
            'scss' => [], // force to create xxx-import.scss
        ];
        $addonType = 'theme';

        /** @var ThemeService $themeService */
        $themeService = app(ThemeService::class);

        $themeName = $themeService->getCurrentTheme();
        $processedThemes = [];
        // walk up the parent themes (stop on circular parents)
        while ($themeName && !isset($processedThemes[$themeName])) {
            $processedThemes[$themeName] = true;

            $assetPath = $themeService->getPath('resources/assets', $themeName);
            $this->getRequiredList($assetPath, $required, $addonType);

            $themeName = $themeService->getParentThemeName($themeName);
        }

        $this->implementFiles($addonType, $required);
    }

    /**
     * Adds all files in $assetPath matching the asset config to $required[extension][].
     *
     * @param  string  $assetPath
     * @param  array   $required
     * @param  string  $addonType  'module' or 'theme'
     *
     * @return void
     */
    public function getRequiredList(string $assetPath, array &$required, string $addonType): void
    {
        app('system_base_file')->runDirectoryFiles($assetPath,
            function ($path, $sourcePathInfo) use (&$required, $addonType) {
                if (!($ext = $sourcePathInfo['extension'] ?? '')) {
                    return;
                }
                $configFiles = data_get($this->assetConfig, $addonType.'s.assets.'.$ext.'.files', []);
                foreach ($configFiles as $fileWanted) {
                    if (preg_match('#'.$fileWanted.'#', $path)) {
                        $required[$ext][] = $path;
                    }
                }
            });
    }

}
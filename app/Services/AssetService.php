<?php

namespace Modules\DeployEnv\app\Services;

use Modules\SystemBase\app\Services\Base\BaseService;
use Modules\SystemBase\app\Services\ModuleService;
use Modules\SystemBase\app\Services\ThemeService;
use Nwidart\Modules\Module as NwidartModule;

class AssetService extends BaseService
{
    /**
     * @var ModuleService|null
     */
    protected ?ModuleService $moduleService = null;

    /**
     * Path to generated asset files
     */
    const string STORAGE_PATH_MERCY_GENERATED = 'app/mercy-generated';

    /**
     * Asset Config
     *
     * @var array
     */
    protected array $assetConfig = [
        'modules' => [
            'assets' => [
                'css'  => [
                    'files'  => [
                        'assets/css/.*\.css$',
                    ],
                    'method' => 'include', // include,merge
                ],
                'scss' => [
                    'files'  => [
                        'assets/sass/.*\.scss$',
                    ],
                    'method' => 'include', // include,merge
                ],
                'js'   => [
                    'files'  => [
                        'assets/js/app\.js$',
                    ],
                    'method' => 'include', // include,merge
                ],
            ],
        ],
        'themes'  => [
            'reverse' => true,
            'assets'  => [
                'css'  => [
                    'files'  => [
                        'assets/css/.*\.css$',
                    ],
                    'method' => 'include', // include,merge
                ],
                'scss' => [
                    'files'  => [
                        //                        'assets/sass/.*\.scss$'
                        'assets/sass/app\.scss$',
                    ],
                    'method' => 'include', // include,merge
                ],
                'js'   => [
                    // last_only: default false, set tru if you only need the inherited one instead of use all founds
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

    /**
     * @param  ModuleService  $moduleService
     */
    public function __construct(ModuleService $moduleService)
    {
        parent::__construct();

        $this->moduleService = $moduleService;

        // create folder if not exists ...
        $path = storage_path(self::STORAGE_PATH_MERCY_GENERATED);
        if (!is_dir($path)) {
            app('system_base_file')->createDir($path);
        }
    }

    /**
     * @param  string  $addonType  'module' or 'theme'
     * @param  array   $fileListContainer
     *
     * @return void
     */
    public function implementFiles(string $addonType, array $fileListContainer): void
    {
        //
        foreach ($fileListContainer as $extension => $fileList) {

            // clear
            $destinationPath = storage_path(self::STORAGE_PATH_MERCY_GENERATED.'/'.$addonType.'s-import.'.$extension);
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

            //
            foreach ($fileList as $file) {
                if (file_exists($file)) {
                    if ($content = trim(file_get_contents($file))) {

                        $relativeFile = app('system_base_file')->makeRelativePath($file, base_path(),
                            storage_path(self::STORAGE_PATH_MERCY_GENERATED));
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
                                    file_put_contents($destinationPath, "@use '$relativeFile' as *;\n", FILE_APPEND);
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
            $assetPath = $moduleService->getPath('resources/assets', $module->getName());

            $this->getRequiredList($assetPath, $required, $addonType);

            return true;
        });

        $this->implementFiles($addonType, $required);
    }

    /**
     * @return void
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

        //
        $themeName = $themeService->getCurrentTheme();
        while ($themeName) {

            $assetPath = $themeService->getPath('assets', $themeName);

            $this->getRequiredList($assetPath, $required, $addonType);

            // next loop
            $themeName = $themeService->getThemeParent($themeName);

        }

        //
        $this->implementFiles($addonType, $required);
    }

    /**
     * @param  string  $assetPath
     * @param  array   $required
     * @param  string  $addonType
     *
     * @return void
     */
    public function getRequiredList(string $assetPath, array &$required, string $addonType): void
    {
        app('system_base_file')->runDirectoryFiles($assetPath,
            function ($path, $sourcePathInfo) use (&$required, $addonType) {
                $ext = $sourcePathInfo['extension'];
                $configFiles = data_get($this->assetConfig, $addonType.'s.assets.'.$ext.'.files', []);
                foreach ($configFiles as $fileWanted) {
                    if (preg_match('#'.$fileWanted.'#', $path)) {
                        $required[$ext][] = $path;
                    }
                }
            });
    }

}
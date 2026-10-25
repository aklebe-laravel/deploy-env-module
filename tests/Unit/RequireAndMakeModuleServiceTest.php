<?php
declare(strict_types = 1);

namespace Modules\DeployEnv\tests\Unit;

use Modules\DeployEnv\app\Services\MakeModuleService;
use Modules\DeployEnv\app\Services\RequireModuleService;
use Modules\DeployEnv\app\Services\RequireThemeService;
use Modules\SystemBase\app\Services\ModuleService;
use Modules\SystemBase\app\Services\ThemeService;
use Modules\SystemBase\tests\Unit\Isolated\IsolatedTestCase;
use ReflectionMethod;
use ReflectionProperty;

class RequireAndMakeModuleServiceTest extends IsolatedTestCase
{
    public function test_setVendor(): void
    {
        $moduleService = new ModuleService();
        $service = new RequireModuleService($moduleService);

        $this->assertTrue($service->setVendor('acme'));
        $this->assertSame('acme', $service->getVendor());
        $this->assertSame('acme', $moduleService->vendor);
        $this->assertFalse($service->setVendor(''));
    }

    public function test_repository_source_placeholders(): void
    {
        $service = new RequireThemeService(new ThemeService());
        $service->setVendor('acme');
        (new ReflectionProperty($service, 'currentNameGit'))->setValue($service, 'dark-theme');

        $parser = (new ReflectionMethod($service, 'getParser'))->invoke($service);

        $this->assertSame('git@git.local:acme/dark-theme.git', $parser->parse('git@git.local:${{theme_vendor_name}}/${{theme_snake_name_git}}.git'));
        $this->assertSame('acme', $parser->parse('${{module_vendor_name}}'));
    }

    public function test_requireAddonTypeByData_without_repository_source_fails(): void
    {
        $service = new RequireModuleService(new ModuleService());

        $this->assertFalse($service->requireAddonTypeByData(['vendor_name' => 'acme']));
        $this->assertNotEmpty($service->getErrors());
    }

    public function test_generateModuleFiles_from_template(): void
    {
        $template = $this->makeTempDir();
        $destination = $this->makeTempDir().'/MyShop';
        $this->makeFile($template.'/module.json.extToDelete', '{"name": "${{module_name}}", "alias": "${{module_name_lower}}"}');
        $this->makeFile($template.'/app/Providers/${{module_name}}ServiceProvider.php.extToDelete', 'class ${{module_name}}ServiceProvider {}');
        $this->makeFile($template.'/README.md', '# ${{module_name}}');

        $service = new MakeModuleService(new ModuleService());
        $this->assertTrue($service->generateModuleFiles('MyShop', false, $template, $destination));

        $this->assertSame('{"name": "MyShop", "alias": "my-shop"}', file_get_contents($destination.'/module.json'));
        $this->assertSame('class MyShopServiceProvider {}', file_get_contents($destination.'/app/Providers/MyShopServiceProvider.php'));
        $this->assertSame('# MyShop', file_get_contents($destination.'/README.md'));

        // existing files are kept without $overwrite
        file_put_contents($destination.'/README.md', 'changed');
        $service->generateModuleFiles('MyShop', true, $template, $destination);
        $this->assertSame('changed', file_get_contents($destination.'/README.md'));

        $service->generateModuleFiles('MyShop', true, $template, $destination, true);
        $this->assertSame('# MyShop', file_get_contents($destination.'/README.md'));
    }
}

## Deploy Env

A **required** module for [Mercy Scaffold Application](https://github.com/aklebe-laravel/mercy-scaffold).

This module provides deployments for your environments using artisan:
fill database data, run sql scripts, artisan or shell commands, require modules/themes by git,
build frontend assets and import files.

Requirements: module **SystemBase**. The `mercy:*` commands and `DeployEnvBase::getDeployment()` use
`AklebeLaravel\MercyPhpDeployment` (YAML setup configs in `resources/setup`).

### Config

| Env var                                             | Default                   | Meaning                                                         |
|-----------------------------------------------------|---------------------------|-----------------------------------------------------------------|
| `MODULE_DEPLOYENV_REQUIRE_MODULES_STRATEGY`         | `DEFAULT`                 | git strategy for required modules/themes, see "Require modules" |
| `MODULE_DEPLOYENV_MAKE_MODULE_AUTHOR_NAME`          | `John Doe`                | author for `deploy-env:make-module`                             |
| `MODULE_DEPLOYENV_MAKE_MODULE_AUTHOR_EMAIL`         | `john-doe@localhost.test` | author email for `deploy-env:make-module`                       |
| `MODULE_DEPLOYENV_MAKE_MODULE_COMPOSER_VENDOR_NAME` | `my-vendor`               | composer vendor for `deploy-env:make-module`                    |

Every module in `/Modules` can deploy its own stuff by `Modules/MyModule/config/module-deploy-env.php`,
the app itself by `config/module-deploy-env.php`.
`ModuleBaseServiceProvider::registerConfig()` (SystemBase) merges them, so they are accessible by
`config('my-module.module-deploy-env')`.

### Console

| Command                                                                 | Meaning                                                                               |
|-------------------------------------------------------------------------|---------------------------------------------------------------------------------------|
| `deploy-env:terraform-modules`                                          | run deployments of modules, app and business imports, see below                       |
| `deploy-env:module-info [MyModule]`                                     | list of all modules ordered by priority, or details of one module                     |
| `deploy-env:make-module MyNewModule [--update]`                         | create a new module from the stubs                                                    |
| `deploy-env:former MyModule [--classes=a,b] [--no-dt] [--no-model]`     | create form/datatable/model classes (by listeners of other modules)                   |
| `deploy-env:build-frontend [--dev]`                                     | clear caches, build mercy assets, `npm run build` (or `dev`), cache views             |
| `deploy-env:build-mercy-assets`                                         | create `storage/app/mercy-generated/{modules,themes}-import.{js,css,scss}`            |
| `deploy-env:auto-import {fileRegEx} [--root=] [--dir=] [--type=]`       | import files from `storage/app/imports`, see "Imports"                                |
| `deploy-env:db-import {sql_file} [--db_name=] [--drop] [--no_db_check]` | import a sql dump (into another database)                                             |
| `deploy-env:fix-gitignore`                                              | remove everything from the git index, add it again (respecting .gitignore) and commit |
| `mercy:app {services}`                                                  | run comma separated services, see below                                               |
| `mercy:info`                                                            | important env vars, docker hosts/ports and URLs                                       |
| `mercy:sync-npm`                                                        | create/update proxy hosts in Nginx Proxy Manager from YAML `setup.npm.hosts`          |

#### mercy:app services

`php artisan mercy:app cache,build-frontend` runs the services in the given order and stops at the first failure:

| Service          | Meaning                                                                                    |
|------------------|--------------------------------------------------------------------------------------------|
| `cache`          | clear all caches                                                                           |
| `ide`            | generate ide-helper files                                                                  |
| `view`           | `view:cache`                                                                               |
| `build-frontend` | `deploy-env:build-frontend`                                                                |
| `update`         | composer, npm, clear caches, build frontend, optimize (depends on `setup.kernel.dev_mode`) |
| `migrate`        | `migrate --force` and `deploy-env:terraform-modules`                                       |
| `require`        | clone/update modules and themes of YAML `setup.requires`                                   |
| `files`          | fix permissions of `storage` and `bootstrap/cache` (owner `WWWUSER:WWWGROUP`)              |
| `healthy`        | permissions, check the webserver on port 80, clear config/view cache                       |
| `test`           | print app name, version and env                                                            |

#### Require modules and themes

YAML `setup.requires` lists blocks like:

```yaml
setup:
  requires:
    - type: module                # or theme
      vendor: aklebe-laravel
      repository: "git@github.com:${{module_vendor_name}}/${{module_snake_name_git}}.git"
      branch: "dev-master"        # or a composer constraint like "^2.0"
      dev_mode: true              # skip repositories with local changes
      names:
        - system-base
        - acl
```

Placeholders in `repository`: `module_vendor_name`, `module_snake_name`, `module_snake_name_git`
(and the same with `theme_`). The git name always ends with `-module` / `-theme`.

Strategy (`MODULE_DEPLOYENV_REQUIRE_MODULES_STRATEGY`): `DEFAULT`/`PULL` checkout the best tag or branch and pull,
`MERGE` checkout only, `NONE` fetch only.

#### Make new modules

```bash
php artisan deploy-env:make-module MyNewModule
```

Use `--update` to create missing files of an existing module (if a new version needs more files).
Existing files are never overwritten. Afterward `composer dump-autoload` runs.

The stubs are located in `Modules/DeployEnv/resources/module-stubs/ModuleTemplate`.
Files ending with `.extToDelete` lose this extension. Placeholders like `${{module_name}}` in file names and content:

| Placeholder           | Example                   |
|-----------------------|---------------------------|
| `module_name`         | `MyModule`                |
| `module_name_lower`   | `my-module`               |
| `module_author_name`  | `John Doe`                |
| `module_author_email` | `john-doe@localhost.test` |
| `module_vendor_name`  | `my-vendor`               |

### Deployments (terraform)

```bash
php artisan deploy-env:terraform-modules [--module_name=] [--module_version=] [--force]
```

- without options: all enabled modules (by priority), then the app, then the business imports
- `--module_name=Acl`: this module only
- `--module_name=local:business-imports`: business imports only
- `--module_version=0003`: this deployment ident only
- `--force`: run again, even if already done

Like migrations, successful deployments are stored in the table `module_deployenv_deployments`
(module, version = deployment ident, batch). Each deployment ident runs once.

Business imports are all PHP files in `storage/app/imports/module-deploy-env/` (sorted by name),
their scripts are located in `storage/app/imports/module-deploy-env/{ident}/{cmd}/`.
In the Mercy Scaffold this folder is `project/business_imports` of the project repository (linked by the container).
They use the same config format as modules (see below), so they are stored in `module_deployenv_deployments`
with module `local:business-imports`. To run them again after changing your data:

```bash
php artisan deploy-env:terraform-modules --module_name=local:business-imports --force
```

#### Deployment config

`Modules/MyModule/config/module-deploy-env.php`:

```php
return [
    'deployments' => [
        // Identifier to remember this deployment was done.
        '0001' => [
            [
                'cmd'     => 'models',
                'sources' => ['acl-resources.php', 'acl-groups.php'],
            ],
            [
                'cmd'     => 'raw_sql',
                'sources' => ['test-tables.sql'],
            ],
            [
                'cmd'     => 'artisan',
                'sources' => ['cache:clear'],
            ],
        ],
        '0002' => [
            [
                'cmd'        => 'raw_sql',
                // all conditions have to be true, otherwise the step is skipped
                'conditions' => [
                    [
                        'db_table_exists'            => 'acl_resources',
                        'db_table_not_exists'        => 'acl_tests_xyz',
                        'db_table_column_exists'     => 'acl_resources.id',
                        'db_table_not_column_exists' => 'acl_resources.missing_column',
                        'module_enabled'             => 'Acl',
                        'module_not_enabled'         => 'OtherModule',
                        'function'                   => [MyCondition::class, 'check'], // or a Closure
                    ],
                ],
                'sources'    => ['test-acl-tables.sql'],
            ],
        ],
    ],
];
```

Commands (`cmd`):

| cmd         | sources                                              |
|-------------|------------------------------------------------------|
| `models`    | model definition files, see below                    |
| `raw_sql`   | sql files, executed by `DB::unprepared()`            |
| `artisan`   | artisan command lines like `cache:clear`             |
| `process`   | shell commands (no timeout)                          |
| `functions` | Closures, called like `fn(string $cmd, string $key)` |

Script files are located in `{module}/resources/module-deploy-env/{ident}/{cmd}/` like
`Modules/Acl/resources/module-deploy-env/0001/models/acl-resources.php`
(app: `resources/module-deploy-env/...`).
A failing step stops the deployment, then the ident is not stored and runs again next time.

#### Model definition (cmd "models")

```php
return [
    // class of eloquent model (required)
    'model'                => \Modules\CentralUi\app\Models\User::class,
    // columns to find an existing model (AND WHERE), also 'relation.column'
    'uniques'              => ['email'],
    // create missing models (default true)
    'can_create'           => true,
    // update existing models (default false)
    'can_update'           => true,
    // fields not overwritten on update
    'ignore_update_fields' => ['password'],
    // separator to split unique values into multiple models (default ','), false: only arrays are split
    'unique_separator'     => ',',
    // values of the unique column(s) to delete
    'auto_delete'          => ['old@my-domain.de'],
    // relations to update/create by '#sync_relations' in the data rows
    'relations'            => [
        'observeItems' => [
            // relation method (HasMany: rows are upserted)
            'method'      => 'observeItems',
            // unique columns of the related rows, 'vm.extern_vm_id' is resolved to the foreign key vm_id
            'columns'     => ['vm.extern_vm_id'],
            // delete related rows not listed (default false)
            'auto_delete' => false,
        ],
        'aclGroups'    => [
            // relation method (BelongsToMany: sync)
            'method'      => 'aclGroups',
            // column(s) to find the related models by the values in '#sync_relations'
            'columns'     => 'name',
            // true: sync() removes groups not listed, false: syncWithoutDetaching()
            'auto_delete' => false,
        ],
    ],
    // data rows (or a Closure returning them), the key 'data' is required!
    'data'                 => [
        [
            'is_enabled'      => 1,
            'name'            => 'Andi',
            'email'           => 'andi@my-domain.de',
            'password'        => '1234567',
            '#sync_relations' => [
                'observeItems' => [
                    ['is_enabled' => 1, 'position' => 1000, 'vm.extern_vm_id' => '121'],
                    ['is_enabled' => 1, 'position' => 2000, 'vm.extern_vm_id' => '115'],
                ],
                'aclGroups'    => ['Admins', 'Members'],
            ],
        ],
    ],
];
```

> **Note:** The rows must be located in `'data' => [...]`. Rows without this key (like `return [..., [[...]]]`)
> are silently ignored, nothing is imported.

How a row is processed:

1. Find an existing model by `uniques` (AND WHERE).
2. Found: update it if `can_update` is true (without `ignore_update_fields`), otherwise skip the row (also its relations).
3. Not found: create it if `can_create` is true, otherwise skip the row silently (useful if models are created elsewhere, like users imported by LDAP/Authentik).
4. Create/update the relations listed in `relations` by the row's `#sync_relations`.
   Keys starting with `#` are never written to the model.

##### Multiple models by one row

A value of a `uniques` column can contain multiple values. The row is then processed once per value
with the same data and the same `#sync_relations`. This is useful to give many users the same groups and items:

```php
'uniques' => ['name'],
'data'    => [
    [
        // comma separated string ...
        'name'            => 'david,uwe,frank,user1,user2',
        // ... or an array (same result)
        // 'name'         => ['david', 'uwe', 'frank', 'user1', 'user2'],
        'is_enabled'      => 1,
        '#sync_relations' => [
            'aclGroups' => ['Members', 'Server Proxmox Observers'],
        ],
    ],
],
```

- Values are trimmed, empty values and duplicates are removed (`'david, uwe,,uwe'` => `david`, `uwe`).
- Only `uniques` columns are split, all other columns stay unchanged.
- Multiple multi-value `uniques` columns create every combination (`'name' => 'a,b'`, `'code' => 'x,y'` => `a/x`, `a/y`, `b/x`, `b/y`).
- `'unique_separator' => ';'` uses another separator. `'unique_separator' => false` disables splitting strings,
  use this if a unique value can contain a comma (like `'Server, Proxmox'`). Arrays are always split.

##### Relations (`#sync_relations`)

The relation type is detected by the relation method:

| Relation type   | Detected by   | `#sync_relations` items                     | `auto_delete`                                       |
|-----------------|---------------|---------------------------------------------|-----------------------------------------------------|
| `HasMany`       | `upsert()`    | rows of the related model                   | delete related rows not listed                      |
| `BelongsToMany` | `sync()`      | values (or list of values) for `columns`    | `true`: `sync()`, `false`: `syncWithoutDetaching()` |
| `BelongsTo`     | `associate()` | one value (or list of values) for `columns` | -                                                   |

For `HasMany`, the parent foreign key (like `user_id`) and `columns` are the upsert keys.
A column like `vm.extern_vm_id` is resolved: the related model of `vm()` is searched by `extern_vm_id`
and its id is written to the foreign key (`vm_id`). If it's not found, an error is logged and the remaining
relations of this model are skipped (the import itself continues with the next row).

#### Parsing env content

`DeploymentService::parseEverything()` replaces env vars like `${APP_DOMAIN}` and these placeholders:

| Placeholder               | Value                                                                     |
|---------------------------|---------------------------------------------------------------------------|
| `${{hash content='...'}}` | short URL-safe hash (random without content)                              |
| `${{app_ip}}`             | config `app.ip`                                                           |
| `${{container_id}}`       | internal docker hostname `app-{compose project name}.internal`            |
| `${{host_depend_env}}`    | `app.ip`, otherwise the IP on production and the container name elsewhere |
| `${{port_depend_env}}`    | `app.port` on production, otherwise `80`                                  |

Unknown placeholders stay unchanged (or throw with `$strict = true`).

### Imports

`deploy-env:auto-import "\.csv$"` dispatches the event `ImportContent` for every matching file in
`storage/app/imports`. The type is taken from `--type` or from the filename prefix like `products-2026.csv` (=> `product`).
Listeners of other modules extend `Listeners\ImportContent` (set `$requiredTypesSingular`) and `Listeners\ImportRow`.
CSV files (delimiter `;`) are read row by row and `ImportRow` is dispatched per row.

### Create forms and datatables

If you have the modules `Form` and `DataTable` installed:

```bash
php artisan deploy-env:former MyModule --classes=country,some-thing,AnyThing
```

This creates the eloquent model, the form and datatable classes per class name, like:

```
Modules/MyModule/app/Models/Country.php
Modules/MyModule/app/Forms/Country.php
Modules/MyModule/app/Http/Livewire/Form/Country.php
Modules/MyModule/app/Http/Livewire/DataTable/Country.php
```

Existing files are not changed. Without `--classes` all models of the module are used.
The files are created by listeners of the event `DeployEnvFormer`, so other modules can add more files.

### Tests

Unit tests in `tests/Unit` run **without** booting the Laravel application. They extend
`Modules\SystemBase\tests\Unit\Isolated\IsolatedTestCase` (module SystemBase).

```bash
vendor/bin/phpunit Modules/DeployEnv/tests/Unit
```

The tests in `tests/Feature` boot the application and need network access (git) or env values.

## Installation

Part of the Mercy core: the scaffold always installs it (`resources/setup/core.yaml`), it is not listed in `requires`.

## License

MIT, see [LICENSE.md](LICENSE.md).

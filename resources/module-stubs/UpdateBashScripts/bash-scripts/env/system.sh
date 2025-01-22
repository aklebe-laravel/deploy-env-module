#!/bin/bash
# =============================================
# This file was generated by ui.sh respective
# php artisan deploy-env:update-bash
# depending on you laravel .env file
# =============================================

# =============================================
# Env system:
# something like: local,dev,int,prod,dev-channel,local-ak,prod-fake,prod-2.3.5,etc...
# used for folders and/or branches
# =============================================
destination_env_system="${{config name="app.env"}}"

# =============================================
# Env Box:
#   default: server like production hostings
#   possible values: "default", "zepgram", ...
# =============================================
destination_env_box=""

# =============================================
# Source settings
# Used for exported dumps (the search values for "search and replace")
# =============================================
source_host="${{env name="DB_HOST"}}"
source_db_user="user1"

# =============================================
# Destination settings
# =============================================
destination_db_host="${{env name="DB_HOST"}}"
destination_db_port="${{env name="DB_PORT"}}"
destination_db_name="${{env name="DB_DATABASE"}}"
destination_db_user="${{env name="DB_USERNAME"}}"
destination_db_password="${{env name="DB_PASSWORD"}}"
destination_app_name="${{env name="APP_NAME"}}"
destination_mercy_root_path="${{base_path}}"

# =============================================
# Composer
# values like "composer" or "./composer.phar"
# =============================================
composer_executable="composer"

# =============================================
# Miscellaneous
# =============================================
mercyModuleDirectory="${{config name="modules.namespace"}}"
mercyThemeDirectory="${{config name="theme.namespace"}}"
defaultBranch="${{config name="mercy-dependencies.default_branch"}}"
dark_theme=1 # 0 or 1
delete_dump=1

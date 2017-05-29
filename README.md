# Drush CMI tools
Provides advanced CMI import and export functionality for CMI workflows.

## Use case
Say you're working on a local development environment for a project where the client is adding and editing configuration.
For example, the project might be using [Contact Storage](https://drupal.org/project/contact_storage) or [YAML Form](https://drupal.org/project/yamlform) for user interaction. Each of these and their associated fields, form and view displays are a config object. As the client is editing these, you don't want that configuration tracked in your source control.

So you start working on a new feature, the first thing you do is sync down a QA or production database and then you run your config import so that your local environment is in a clean state. 

You work on some features and the time has come to export those changes to your config export folder, in order to check in the new work into git ready for deployment.

Enter `drush cexy`

# drush cexy
Its like `drush cex` but with 74% more shiny<sup>[1](#ref1)</sup>.

So the normal `drush cex` command comes with a `--skip-modules` option that prevents configuration from say devel module from being exported. But let's go back to our original use case.

We want to export all configuration, but we want to exclude certain patterns.

This is where the `--ignore-list` option of `drush cexy` comes in.

In our project we have a `./drush` folder, so we stick a file in their called `config-ignore.yml` with contents as follows.

```yml
ignore:
  - field.field.contact_message.*
  - field.storage.contact_message.*
  - contact.form.*
  - core.entity_form_display.contact_message*
  - core.entity_form_display.contact_form*
  - core.entity_view_display.contact_message*
  - core.entity_view_display.contact_form*
  - system.site
  - workbench_email.workbench_email_template.*
```

You'll note there are some wildcards there. We're ignoring all contact message fields and forms as well as any form or view display configuration. Additionally we're ignoring [Workbench Email](https://drupal.org/project/workbench_email) templates and the system site settings.

So now we run `drush cexy` like so

```
drush cexy --destination=/path/to/config-export --ignore-list=/path/to/drush/config-ignore.yml
```

So what this does is export the active configuration, and then apply the ignore list to remove unwanted configuration.

So now when you run `git status` you should only see changes you want to commit.

# Single install configuration
  
So lets assume you're working on a feature branch that requires installation of the [Google Analytics](https://drupal.org/project/google_analytics) module.

You download and enable the module

```bash
drush dl google_analytics
drush en -y google_analytics
```

And then you export your configuration with `drush cexy` using your build tool of choice (`make` in this case - cause remembering all the flags is bound to go wrong)

```
make export
```

After running that you find you have a new file in your exported configuration folder:

```
google_analytics.settings
```

Now you know that you want this configuration to be editable by the client, as they'll have different GA urchin codes on different environments.

So you don't want to check this into git. But, you do need it to be deployed at least once. And that's where `drush cexy`'s sibling comes in `drush cimy`.

# drush cimy

`drush cimy` is the import equivalent of `drush cexy`. Experts estimate it increases your productivity by 94%<sup>[2](#ref2)</sup>.

So returning to our single install of the google analytics settings. You'd just exported your config using `drush cexy` and found yourself with a new `google_analytics.settings.yml` file that you needed to deploy, but only once.

`drush cimy` combines the following features

* The power of `drush cim --partial`
* The ability to perform config deletes
* The ability to perform one-time installs

The format is as follows

```
drush cimy --source=/path/to/config-export --install=/path/to/config-install --delete-list=/path/to/config-delete.yml
```

So we move the google_analytics.settings.yml out of our `config-export` folder and into our `config-install` folder. And then we add it to our `drush/config-ignore.yml` file, so it doesn't get exported in the future.

## Partial imports

So as alluded above, `drush cimy` is similar to `drush cim --partial` in that it does partial imports. 

The way `drush cim --partial` works is equivalent to the following

* firstly it creates a temporary folder
* then it exports all active configuration
* then it copies your nominated config-export folder (the one under source control) over the top (in the temporary folder)
* then it imports from the temporary folder

So what you get imported is all active config plus and new config from the config export, with changes in the exported config taking precedence over the active config.

The main pain point with using `--partial` is you don't get config deletes.

e.g. if you delete a config file from git (it is no longer in your config-export folder) because it is still present in the active configuration, it still remains after import.

So why is this a problem. So let's consider a scenario where someone enabled `dblog` module on QA, and saved the settings so that `dblog.settings.yml` is in the active configuration.
 
Your `core.extensions.yml` that is tracked in git does not contain `dblog` module. But `dblog.settings.yml` depends on `dblog` module.

So you work away on your feature and go to deploy to QA. But the import step of your deployment automation fails, because `--partial` places a copy of `dblog.settings.yml` in the temporary folder and tries to import it, but because `dblog` module is going to be disabled by the import, you have an unmet config dependency.

This is where the `--delete-list` flag kicks in. Let's look at a sample delete list file

```yml
delete:
  - dblog.settings
```

So this is where `drush cimy` varies from `drush cim`, its (equivalent) logic is as follows

* As with `drush cim --partial`, first it creates a temporary folder
* Move one-time install configuration into the folder first - so that active configuration takes precendence over initial state
* export all active configuration
* delete any configuration found in active configuration that is listed in the delete list
* copy the nominated config-export (tracked in source control) over the top, taking final precendence
* import the result

So this means you get the good bits of partial imports, without the dependency dramas that can result. It also allows you to perform valid deletes, something that isn't possible with `drush cim --partial` - for example you might want to delete a field from active configuration. Previously you'd have to write an update hook to do that before you performed your config import. Now you just list it in the config-delete.yml file 

# Installation

```bash
cd ~/.drush
wget https://raw.githubusercontent.com/previousnext/drush_cmi_tools/8.x-1.x/drush_cmi_tools.drush.inc
drush cc drush
```

### As Composer dependency

First, update composer.json manually. Then call `require` command.
```
"repositories": [
    {
        "url": "https://github.com/previousnext/drush_cmi_tools.git",
        "type": "git"
    }
],
"require": {
    "drupal/drush-cmi-tools": "dev-8.x-1.x"
},
```
`$ composer require drupal/drush-cmi-tools:dev-8.x-1.x`

# References

* <a name="ref1"></a>1. According to a random survey of this one guy I met on the train.
* <a name="ref2"></a>2. Granted they were experts in rocket science and had never heard of Drupal.

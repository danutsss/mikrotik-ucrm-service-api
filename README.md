# MikroTik & UCRM Service API

## How it works?

This plugin handles the creation of PPP instances into Winbox. These instances are created automatically after a service is created for an UCRM client.

The instance is completed with client's information that I get through UCRM API.

## Configuration

At the moment, if you want to use the plugin for your platform, you will need to configure the connection to Winbox manually from `src/src/Plugin.php`.

## Future updates

* Configure the plugin, directly from the plugin's page.

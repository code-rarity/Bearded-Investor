<?php
/**
 * This file represents an example of the code that themes would use to register
 * the required plugins.
 *
 * It is expected that theme authors would copy and paste this code into their
 * functions.php file, and amend to suit.
 *
 * @see http://tgmpluginactivation.com/configuration/ for detailed documentation.
 *
 * @package    TGM-Plugin-Activation
 * @subpackage Example
 * @version    2.6.1 for parent theme Salient for publication on ThemeForest
 * @author     Thomas Griffin, Gary Jones, Juliette Reinders Folmer
 * @copyright  Copyright (c) 2011, Thomas Griffin
 * @license    http://opensource.org/licenses/gpl-2.0.php GPL v2 or later
 * @link       https://github.com/TGMPA/TGM-Plugin-Activation
 */

/**
 * Include the TGM_Plugin_Activation class.
 */
require_once get_template_directory() . '/nectar/tgm-plugin-activation/class-tgm-plugin-activation.php';

add_action( 'tgmpa_register', 'nectar_register_required_plugins' );
/**
 * Register the required plugins for this theme.
 *
 * In this example, we register two plugins - one included with the TGMPA library
 * and one from the .org repo.
 *
 * The variable passed to tgmpa_register_plugins() should be an array of plugin
 * arrays.
 *
 * This function is hooked into tgmpa_init, which is fired within the
 * TGM_Plugin_Activation class constructor.
 */
function nectar_register_required_plugins() {

	/**
	 * Array of plugin arrays. Required keys are name and slug.
	 * If the source is NOT from the .org repo, then source is also required.
	 */
	$plugins = array(

		array(
        'name'               => 'Salient WPBakery Page Builder', // The plugin name
        'slug'               => 'js_composer_salient', // The plugin slug (typically the folder name)
        'source'             => get_template_directory() . '/plugins/js_composer_salient.zip', // The plugin source
        'required'           => true, // If false, the plugin is only 'recommended' instead of required
        'version'            => '7.8.2', // E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
        'force_activation'   => false, // If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
        'force_deactivation' => false, // If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
    ),
		array(
        'name'               => 'Salient Core',
        'slug'               => 'salient-core',
        'source'             => get_template_directory() . '/plugins/salient-core.zip',
        'required'           => true,
        'version'            => '3.0.6',
        'force_activation'   => false,
        'force_deactivation' => false,
    ),
		array(
        'name'               => 'Salient Demo Importer',
        'slug'               => 'salient-demo-importer',
        'source'             => get_template_directory() . '/plugins/salient-demo-importer.zip',
        'required'           => false,
        'version'            => '1.7',
        'force_activation'   => false,
        'force_deactivation' => false,
    ),
		array(
        'name'               => 'Salient Social',
        'slug'               => 'salient-social',
        'source'             => get_template_directory() . '/plugins/salient-social.zip',
        'required'           => false,
        'version'            => '1.2.5',
        'force_activation'   => false,
        'force_deactivation' => false,
    ),
		array(
        'name'               => 'Salient Widgets',
        'slug'               => 'salient-widgets',
        'source'             => get_template_directory() . '/plugins/salient-widgets.zip',
        'required'           => false,
        'version'            => '1.2',
        'force_activation'   => false,
        'force_deactivation' => false,
    ),
		array(
        'name'               => 'Salient Portfolio',
        'slug'               => 'salient-portfolio',
        'source'             => get_template_directory() . '/plugins/salient-portfolio.zip',
        'required'           => false,
        'version'            => '1.8.1',
        'force_activation'   => false,
        'force_deactivation' => false,
    ),
		array(
        'name'               => 'Salient Nectar Slider',
        'slug'               => 'salient-nectar-slider',
        'source'             => get_template_directory() . '/plugins/salient-nectar-slider.zip',
        'required'           => false,
        'version'            => '1.7.7',
        'force_activation'   => false,
        'force_deactivation' => false,
    ),
		array(
        'name'               => 'Salient Home Slider',
        'slug'               => 'salient-home-slider',
        'source'             => get_template_directory() . '/plugins/salient-home-slider.zip',
        'required'           => false,
        'version'            => '1.4.1',
        'force_activation'   => false,
        'force_deactivation' => false,
    ),
		array(
        'name'               => 'Salient Shortcodes',
        'slug'               => 'salient-shortcodes',
        'source'             => get_template_directory() . '/plugins/salient-shortcodes.zip',
        'required'           => false,
        'version'            => '1.5.4',
        'force_activation'   => false,
        'force_deactivation' => false,
        ),
        array(
        'name'               => 'Salient Custom Branding',
        'slug'               => 'salient-custom-branding',
        'source'             => get_template_directory() . '/plugins/salient-custom-branding.zip',
        'required'           => false,
        'version'            => '1.0.0',
        'force_activation'   => false,
        'force_deactivation' => false,
        ),

	);


	/**
	 * Array of configuration settings. Amend each line as needed.
	 * If you want the default strings to be available under your own theme domain,
	 * leave the strings uncommented.
	 * Some of the strings are added into a sprintf, so see the comments at the
	 * end of each line for what each argument will be.
	 */
	$config = array(
		'id'           => 'salient',               // Unique ID for hashing notices for multiple instances of TGMPA.
		'default_path' => '',                      // Default absolute path to bundled plugins.
		'menu'         => 'tgmpa-install-plugins', // Menu slug.
		'parent_slug'  => 'themes.php',            // Parent menu slug.
		'capability'   => 'edit_theme_options',    // Capability needed to view plugin install page, should be a capability associated with the parent menu used.
		'has_notices'  => true,                    // Show admin notices or not.
		'dismissable'  => true,                    // If false, a user cannot dismiss the nag message.
		'dismiss_msg'  => '',                      // If 'dismissable' is false, this message will be output at top of nag.
		'is_automatic' => false,                   // Automatically activate plugins after installation or not.
		'message'      => '',                      // Message to output right before the plugins table.
	);

	tgmpa( $plugins, $config );

}
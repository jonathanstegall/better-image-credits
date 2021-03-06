<?php
/*
Plugin Name: Better Image Credits
Plugin URI: http://vdvn.me/pga
Description: Adds credits and link fields for media uploads along with a shortcode and various options to display image credits in your posts.
Version: 1.9
Author: Claude Vedovini
Author URI: http://vdvn.me/
License: GPLv3
Text Domain: better-image-credits

# The code in this plugin is free software; you can redistribute the code aspects of
# the plugin and/or modify the code under the terms of the GNU Lesser General
# Public License as published by the Free Software Foundation; either
# version 3 of the License, or (at your option) any later version.

# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
# EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
# MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
# NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
# LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
# OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
# WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
#
# See the GNU lesser General Public License for more details.
*/


function bic_get_option($option, $default) {
	$options = get_option('better-image-credits-options');

	if ($options && is_array($options)) {
		return (isset($options[$option])) ? $options[$option] : $default;
	}

	return get_option('better-image-credits_' . $option, $default);
}


define('IMAGE_CREDITS_TEMPLATE', bic_get_option('template', '<a href="[link]" target="_blank">[source]</a>'));
define('IMAGE_CREDITS_SEP', bic_get_option('sep', ',&#32;'));
define('IMAGE_CREDITS_BEFORE', bic_get_option('before', '<p class="image-credits">' . __('Image Credits', 'better-image-credits') . ':&#32;'));
define('IMAGE_CREDITS_AFTER', bic_get_option('after', '.</p>'));
define('IMAGE_CREDITS_OVERLAY_COLOR', bic_get_option('overlay_color', ''));

define('IMAGE_CREDIT_BEFORE_CONTENT', 'before');
define('IMAGE_CREDIT_AFTER_CONTENT', 'after');
define('IMAGE_CREDIT_OVERLAY', 'overlay');
define('IMAGE_CREDIT_INCLUDE_BACKGROUND', 'background');
define('IMAGE_CREDIT_INCLUDE_HEADER', 'header');

add_action('plugins_loaded', array('BetterImageCreditsPlugin', 'get_instance'));

class BetterImageCreditsPlugin {

	private static $instance;

	public static function get_instance() {
		if (!self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	function __construct() {
		add_action('init', array($this, 'init'));
		add_action('widgets_init', array(&$this, 'widgets_init'));

		if (is_admin()) {
			require_once 'class-admin.php';
			$this->admin = new BetterImageCreditsAdmin($this);
		}
	}

	function init() {
		// Make plugin available for translation
		// Translations can be filed in the /languages/ directory
		add_filter('load_textdomain_mofile', array(&$this, 'smarter_load_textdomain'), 10, 2);
		load_plugin_textdomain('better-image-credits', false, dirname(plugin_basename(__FILE__)) . '/languages/' );

		if (!is_admin()) {
			// Shortcode
			add_shortcode('image-credits', array($this, 'credits_shortcode'));

			if ($this->display_option(IMAGE_CREDIT_BEFORE_CONTENT) ||
					$this->display_option(IMAGE_CREDIT_AFTER_CONTENT) ||
					$this->display_option(IMAGE_CREDIT_OVERLAY)) {
				add_filter('the_content', array($this, 'add_credits'), 0);
			}

			if ($this->display_option(IMAGE_CREDIT_OVERLAY)) {
				wp_register_style('better-image-credits', plugins_url('style.css', __FILE__), false, '1.0');
				wp_register_script('better-image-credits', plugins_url('script.js', __FILE__), array('jquery'), '1.0', true);
				add_action('wp_enqueue_scripts', array(&$this, 'enqueue_scripts'));
				add_filter('wp_get_attachment_image_attributes', array($this, 'filter_attachment_image_attributes'), 10, 2);
			}
		}
	}

	function widgets_init() {
		include 'class-credits-widget.php';
		register_widget('BetterImageCreditsWidget');
	}

	function smarter_load_textdomain($mofile, $domain) {
		if ($domain == 'better-image-credits' && !is_readable($mofile)) {
			extract(pathinfo($mofile));
			$pos = strrpos($filename, '_');

			if ($pos !== false) {
				# cut off the locale part, leaving the language part only
				$filename = substr($filename, 0, $pos);
				$mofile = $dirname . '/' . $filename . '.' . $extension;
			}
		}

		return $mofile;
	}

	function enqueue_scripts() {
		wp_enqueue_style('better-image-credits');
		wp_enqueue_script('better-image-credits');
	}

	function display_option($option) {
		$options = bic_get_option('display', array());
		if (!is_array($options)) $options = array($options);
		return in_array($option, $options);
	}

	function get_image_credits($template=IMAGE_CREDITS_TEMPLATE) {
		global $post;
		$post_thumbnail_id = 0;
		$attachment_ids = array();
		$credits = array();

		// Check for header image
		if ($this->display_option(IMAGE_CREDIT_INCLUDE_HEADER)) {
			$query_images = new WP_Query(array(
					'post_type' => 'attachment',
					'post_status' => 'inherit',
					'meta_key' => '_wp_attachment_is_custom_header',
					'meta_value' => get_option('stylesheet')));

			foreach ($query_images->posts as $image) {
				if (get_header_image() != $image->guid) continue;
				$attachment_ids[] = $image->ID;
				break;
			}
		}

		// Check for background image
		if ($this->display_option(IMAGE_CREDIT_INCLUDE_BACKGROUND)) {
			$query_images = new WP_Query(array(
					'post_type' => 'attachment',
					'post_status' => 'inherit',
					'meta_key' => '_wp_attachment_is_custom_background',
					'meta_value' => get_option('stylesheet')));

			foreach ($query_images->posts as $image) {
				if (get_background_image() != $image->guid) continue;
				$attachment_ids[] = $image->ID;
				break;
			}
		}

		// Check for post thumbnail and save its ID in an array
		if (function_exists('has_post_thumbnail') && has_post_thumbnail($post->ID)) {
			$attachment_ids[] = $post_thumbnail_id = get_post_thumbnail_id($post->ID);
		}

		// Next look in post content and check for instances of wp-image-[digits]
		if (preg_match_all('/wp-image-(\d+)/i', $post->post_content, $matches)) {
			foreach ($matches[1] as $id) {
				if (!in_array($id, $attachment_ids)) {
					$attachment_ids[] = $id;
				}
			}
		}

		// Finally check for galleries
		$pattern = get_shortcode_regex();
		if (preg_match_all('/'. $pattern .'/s', $post->post_content, $matches)
				&& array_key_exists(2, $matches)
				&& in_array('gallery', $matches[2])) {
			foreach($matches[2] as $index => $tag){
				if ($tag == 'gallery') {
					$params = shortcode_parse_atts($matches[3][$index]);

					if (isset($params['ids'])) {
						$ids = explode(',', $params['ids']);

						foreach($ids as $id){
							$id = (int) $id;
							if ($id > 0) $attachment_ids[] = $id;
						}
					}
				}
			}
		}

		// Make sure the ids only exist once
		$attachment_ids = array_unique($attachment_ids);

		// Go through all our attachments IDs and generate credits
		foreach ($attachment_ids as $id) {
			$att = get_post($id);
			$title = $att->post_title;
			$source = esc_attr(get_post_meta($id, '_wp_attachment_source_name', true));
			$link = esc_url(get_post_meta($id, '_wp_attachment_source_url', true));
			$license = esc_attr(get_post_meta($id, '_wp_attachment_license', true));
			$license_link = esc_attr(get_post_meta($id, '_wp_attachment_license_url', true));

			if (!empty($source)) {
				$credits[$id] = str_replace(
						array('[title]', '[source]', '[link]', '[license]', '[license_link]',
								'{title}', '{source}', '{link}', '{license}', '{license_link}'),
						array($title, $source, $link, $license, $license_link,
								$title, $source, $link, $license, $license_link),
						$template);
			}
		}

		return $credits;
	}

	function credits_shortcode($atts) {
		extract(shortcode_atts(array(
				'sep' => IMAGE_CREDITS_SEP,
				'before' => IMAGE_CREDITS_BEFORE,
				'after'  => IMAGE_CREDITS_AFTER,
				'template' => IMAGE_CREDITS_TEMPLATE
		), $atts, 'image-credits'));

		return $this->the_image_credits($sep, $before, $after, $template);
	}

	function the_image_credits($sep=IMAGE_CREDITS_SEP, $before=IMAGE_CREDITS_BEFORE,
			$after=IMAGE_CREDITS_AFTER, $template=IMAGE_CREDITS_TEMPLATE) {
		return $this->format_credits($this->get_image_credits($template), $sep,
				$before, $after);
	}

	function format_credits($credits, $sep=IMAGE_CREDITS_SEP, $before=IMAGE_CREDITS_BEFORE,
			$after=IMAGE_CREDITS_AFTER) {
		if (!empty($credits)) {
			$credits = array_unique($credits);
			$credits = implode($sep, $credits);
			return $before . $credits. $after;;
		}

		return '';
	}

	function add_credits($content) {
		$credits = $this->get_image_credits();
		$output = $this->format_credits($credits);

		if ($this->display_option(IMAGE_CREDIT_BEFORE_CONTENT)) {
			$content = $output . $content;
		}

		if ($this->display_option(IMAGE_CREDIT_AFTER_CONTENT)) {
			$content .= $output;
		}

		if ($this->display_option(IMAGE_CREDIT_OVERLAY)) {
			$style = (IMAGE_CREDITS_OVERLAY_COLOR) ? ' style="background-color:' . IMAGE_CREDITS_OVERLAY_COLOR . '"' : '';

			foreach ($credits as $id => $c) {
				$content .= '<div class="credits-overlay"' . $style . ' data-target=".wp-image-' . $id . '">' . $c . '</div>';
			}
		}

	    return $content;
	}

	function filter_attachment_image_attributes($attr, $attachment) {
		$attr['class'] = $attr['class'] . ' wp-image-' . $attachment->ID;
		return $attr;
	}

}

/**
 * Legacy template tag for compatibility with the image-credits plugin
 */
function get_image_credits($sep=IMAGE_CREDITS_SEP, $before=IMAGE_CREDITS_BEFORE,
		$after=IMAGE_CREDITS_AFTER, $template=IMAGE_CREDITS_TEMPLATE) {
	the_image_credits($sep, $before, $after, $template);
}

function the_image_credits($sep=IMAGE_CREDITS_SEP, $before=IMAGE_CREDITS_BEFORE,
		$after=IMAGE_CREDITS_AFTER, $template=IMAGE_CREDITS_TEMPLATE) {
	echo get_the_image_credits($sep, $before, $after, $template);
}

function get_the_image_credits($sep=IMAGE_CREDITS_SEP, $before=IMAGE_CREDITS_BEFORE,
		$after=IMAGE_CREDITS_AFTER, $template=IMAGE_CREDITS_TEMPLATE) {
	$plugin = BetterImageCreditsPlugin::get_instance();
	return $plugin->the_image_credits($sep, $before, $after, $template);
}

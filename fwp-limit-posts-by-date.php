<?php
/*
Plugin Name: FWP+: Limit posts by date
Plugin URI: http://projects.radgeek.com/fwp-limit-posts-by-date
Description: Filter syndicated posts by date or total number of posts, allowing you to set limits so that posts which are too old will not be syndicated.
Version: 2011.0210
Author: Charles Johnson
Author URI: http://radgeek.com/
License: GPL
*/

/**
 * @package FWPLimitPostsByDate
 * @version 2011.0204
 *
 * Many thanks to the the folks at <http://iheartsl.com/> and
 * <http://metavirtual.us/> for their generous support of the FeedWordPress
 * project and for commissioning this add-on module.
 */

 /*DBG*/ add_action('init', function () {
 		 add_theme_support('post-thumbnails');
 });
 
$dater = new FWPLimitPostsByDate;

class FWPLimitPostsByDate {
	var $name;

	function FWPLimitPostsByDate () {
		$this->name = strtolower(get_class($this));

		// Hook us in
		add_action(
			/*hook=*/ 'init',
			/*function=*/ array(&$this, 'init')
		);

		add_filter(
			/*hook=*/ 'pre_get_posts',
			/*function=*/ array(&$this, 'pre_get_posts'),
			/*priority=*/ -100,
			/*arguments=*/ 1
		);
		add_action(
			/*hook=*/ 'template_redirect',
			/*function=*/ array(&$this, 'redirect_expired'),
			/*priority=*/ -100
		);
		add_filter(
			/*hook=*/ 'syndicated_feed_items',
			/*function=*/ array(&$this, 'syndicated_feed_items'),
			/*priority=*/ 10,
			/*arguments=*/ 2
		);

		add_filter(
			/*hook=*/ 'syndicated_item',
			/*function=*/ array(&$this, 'syndicated_item'),
			/*priority=*/ 10,
			/*arguments=*/ 2
		);

		add_filter(
			/*hook=*/ 'syndicated_post',
			/*function=*/ array(&$this, 'syndicated_post'),
			/*priority=*/ 10,
			/*arguments=*/ 2
		);

		add_filter('feedwordpress_update_complete', array(&$this, 'process_expirations'), 1000, 1);
		
		// Set up configuration UI
		add_action(
			/*hook=*/ 'feedwordpress_admin_page_feeds_meta_boxes',
			/*function=*/ array(&$this, 'add_settings_box'),
			/*priority=*/ 100,
			/*arguments=*/ 1
		);

		add_action(
			/*hook=*/ 'feedwordpress_admin_page_feeds_save',
			/*function=*/ array(&$this, 'save_settings'),
			/*priority=*/ 100,
			/*arguments=*/ 2
		);
		
		// Set up diagnostics
		add_filter('feedwordpress_diagnostics', array(&$this, 'diagnostics'), 10, 2);
		add_filter('syndicated_feed_special_settings', array(&$this, 'special_settings'), 10, 2);

	} /* FWPLimitPostsByDate constructor */

	function init () {
		$taxonomies = get_taxonomies(array('object_type' => array('post')), 'names');

		// This is a special post type for hiding posts skipped over when
		// limiting by total number from feed.
		register_post_type('syndicated_skipped', array(
			'labels' => array(
				'name' => 'Skipped Posts',
				'singular_name' => 'Skipped Post',
			),
			'description' => 'A syndicated post that was skipped over, but which we must record for record-keeping purposes.',
			'publicly_queryable' => false,
			'show_ui' => false,
			'show_in_nav_menus' => false,
			'exclude_from_search' => true,
			'hierarchical' => false,
			'supports' => array('title', 'author', 'custom-fields'),
			'taxonomies' => $taxonomies,
		));

		// This is a special post status for hiding posts that have expired
		register_post_status('expired', array(
			'label' => 'Expired',
			'exclude_from_search' => true,
			'public' => false,
			'publicly_queryable' => false,
			'show_in_admin_all_list' => false,
			'show_in_admin_status_list' => true,
		));

	} /* FWPLimitPostsByDate::init () */

	function pre_get_posts (&$query) {
		if ($query->is_singular) :
			// This is a special post status for hiding posts that have expired
			register_post_status('expired', array(
				'label' => 'Expired',
				'exclude_from_search' => true,
				'public' => true,
				'publicly_queryable' => true,
				'show_in_admin_all_list' => false,
				'show_in_admin_status_list' => true,
			));
		endif;
		return $query;
	} /* FWPLimitPostsByDate::pre_get_posts () */
	
	function redirect_expired () {
		global $wp_query;
		if (is_singular()) :
			if ('expired'==$wp_query->post->post_status) :
				if (function_exists('is_syndicated') and  is_syndicated($wp_query->post->ID)) :
					$source = get_syndication_feed_object($wp_query->post->ID);
					$redirect_url = $source->setting('expired post redirect to', 'expired_post_redirect_to', NULL);
				else :
					$redirect_url = get_option('feedwordpress_expired_post_redirect_to', NULL);
				endif;
				
				if (!is_null($redirect_url) and strlen(esc_url($redirect_url)) > 0 and 'none'!=$redirect_url) :
					header("HTTP/1.1 301 Moved Permanently");
					header("Location: ".$redirect_url); 
				else :
					// Meh.
					if (!($template = get_404_template())) :
						$template = get_index_template();
					endif;
					if ($template = apply_filters('template_include', $template)) :
						header("HTTP/1.1 410 Gone");
						include($template);
					endif;
				endif;
				exit;
			endif;
		endif;
	} /* FWPLimitPostsByDate::redirect_expired () */
	
	function add_settings_box ($page) {
		add_meta_box(
			/*id=*/ "feedwordpress_{$this->name}_box",
			/*title=*/ __("Limit posts by date"),
			/*callback=*/ array(&$this, 'display_settings'),
			/*page=*/ $page->meta_box_context(),
			/*context=*/ $page->meta_box_context()
		);
	} /* FWPLimitPostsByDate::add_settings_box () */
	
	function magic_clear_status_js () {
		?>
		<script type="text/javascript">
		jQuery(document).ready( function ($) {
			$('.timespan-field').keypress( function() { $(this).removeClass('date-error').removeClass('date-warning'); return true; } );
		} );
		</script>
		<?php
	}

	function date_error_notice ($page, $limit) {
	?>
	<div class="date-error-notice"><p><strong>Invalid timespan or date.</strong>
	FeedWordPress was unable to understand the timespan or date you entered.
	Sorry about that! Check the examples for help with the format, or see
	the <a href="http://us2.php.net/manual/en/function.strtotime.php"><code style="background-color: inherit; color: white;">strtotime()</code></a>
	page for more help. Until you change the timespan or date, FeedWordPress
	can't filter out any posts by their date.</p></div>
	</div>
	<?php
		$this->magic_clear_status_js();
	}
	function future_date_notice ($page, $limit) {
	?>
	<div class="date-warning-notice"><p><strong>Warning: Date in future.</strong>
	The date you entered points to a date in the future. If you
	leave the filter set on this date, <em>nothing will be syndicated at all</em>
	<?php if ($page->for_feed_settings()) : ?> from this feed <?php endif; ?>
	until <?php print date('r', $this->to_ts($limit)); ?>. That's O.K. if
	that's what you want, but make sure that is what you really meant to do
	before you leave it like this.</p></div>
	<?php
		$this->magic_clear_status_js();
	}
	function past_date_notice ($page, $limit) {
	?>
	<div class="date-warning-notice"><p><strong>Warning: Interval in past.</strong> The interval you entered points to a time in the past. If you leave the filter set on this date, <em>all syndicated posts will expire immediately and none will appear at all</em><?php
	if ($page->for_feed_settings()) : ?> from this feed <?php endif; ?>. That&#8217;s O.K. if that&#8217;s what you want, but make sure that is what you really meant to do before you leave it like this.</p></div>
	<?php
		$this->magic_clear_status_js();
	}
	
	function form_input_tip ($page, $limit) {
		FeedWordPressSettingsUI::magic_input_tip_js('.form-input-tip');
	} /* FWPLimitPostsByDate::form_input_tip() */

	function display_settings ($page, $box = NULL) {
		// Date filter: ___________________________
		$globalLimit['filter'] = get_option('feedwordpress_post_date_filter', '');
		$globalLimit['expiration'] = get_option('feedwordpress_post_expiration_date', '');
		$globalExpireFrom = get_option('feedwordpress_post_expiration_date_from', 'syndicated');

		if ($page->for_default_settings()) :
			$limit = array_map('trim', $globalLimit);
			$expireFrom = $globalExpireFrom;
		else :
			$limit['filter'] = trim($page->link->setting('post date filter', NULL, ''));
			$limit['expiration'] = trim($page->link->setting('post expiration date', NULL, ''));
			$expireFrom = trim($page->link->setting('post expiration date from', 'post_expiration_date_from', 'syndicated'));
		endif;

		$inputClasses = array('filter' => array(), 'expiration' => array());
		$postMethods = array('filter' => array(), 'expiration' => array());
		
		foreach ($limit as $key => $span) :
			$inputClasses[$key][] = 'timespan-field';
			if (strlen($span) > 0) :
				$ts = $this->to_ts($span, /*basis=*/ NULL, /*past bias=*/ ('filter'==$key));
				$future = (!is_null($ts) and ($ts > time()));
				
				if (is_null($ts)) :
					// Invalid date; mark as error
					$inputClasses[$key][] = 'date-error';
					$postMethods[$key][] = 'date_error_notice';
				elseif ('filter'==$key and $future) :
					// Future date on filter; issue warning
					$inputClasses[$key][] = 'date-warning';
					$postMethods[$key][] = 'future_date_notice';
				elseif ('expiration'==$key and !$future) :
					// Past date on expiration; issue warning.
					$inputClasses[$key][] = 'date-warning';
					$postMethods[$key][] = 'past_date_notice';
				endif;
			else :
				$inputClasses[$key][] = 'form-input-tip';
				$postMethods[$key][] = 'form_input_tip';
			endif;
		endforeach;

		$numberFilterBits = array(
		'none' => 'as many as appear on the feed',
		'latest_first' => 'only the %d most recent',
		'earliest_first' => 'only the %d earliest new items',
		'rand' => 'no more than %d new items, chosen at random',
		);
		$nfSelector = array(); $nfLabels = array();

		$val = $page->setting(
			"post number filter",
			5
		);
		$globalVal = get_option('feedwordpress_post_number_filter', 5);

		foreach ($numberFilterBits as $index => $label) :
			$input = '<input type="number" min="0" step="1" value="'.esc_html($val).'" size="2" name="post_number_filter['.esc_html($index).']" />';

			$nfSelector[$index] = str_replace('%d', $input, __($label));
			$nfLabels[$index] = sprintf(__($label), $globalVal);
		endforeach;

		$nfParams = array(
		'input-name' => 'post_number_sorter',
		'setting-default' => NULL,
		'global-setting-default' => 'none',
		'labels' => $nfLabels,
		'default-input-value' => 'default'
		);

		$peaSelector = array(
		'hide' => 'hidden but not deleted',
		'redirect' => 'redirected,</label> <label>to this custom URL: %s',
		'trash' => 'moved to the trash can, to be deleted later',
		'nuke' => 'deleted permanently',
		);
		$peaLabels = array();
		$peaUrlGlobal = get_option('feedwordpress_expired_post_redirect_to', 'none');
		$peaUrl = $page->setting('expired post redirect to');
		$peaUrlInput = '<input type="url" name="expired_post_redirect_to" value="'.(('none' != $peaUrl) ? esc_attr($peaUrl) : '').'" placeholder="url" />';
		foreach ($peaSelector as $index => $value) :
			$peaSelector[$index] = sprintf(__($value), $peaUrlInput);
			$peaLabels[$index] = sprintf(__((strpos($value, '%s') ? strtok($value, ',').' to: <code>%s</code>' : $value)), $peaUrlGlobal);
		endforeach;
		
		$peaParams = array(
		'input-name' => 'post_expiration_action',
		'setting-default' => NULL,
		'global-setting-default' => 'hide',
		'default-input-value' => 'default',
		'labels' => $peaLabels,
		);

		$petSelector = array(
		'keep' => 'Nothing. The image file will be kept unchanged in the upload directory.',
		'nuke' => 'Clean up old image, if possible. If the deleted post was the only one that had this image set as its Featured Image, then the orphaned image will be deleted from the upload directory.'
		);
		
		$petParams = array(
		'input-name' => 'post_expiration_thumbnail',
		'setting-default' => NULL,
		'global-setting-default' => 'keep',
		'default-input-value' => 'default',
		'labels' => $peaLabels,
		);

	?>
		<style type="text/css">
		.date-error { background-color: #F77 !important; }
		.date-warning { background-color: #FF7 !important; }
		.date-error-notice { color: white; background-color: #703030; padding: 1.0em; margin: 1.0em; }
		.date-warning-notice { color: black; background-color: #F0F070; padding: 1.0em; margin: 1.0em; }
		.setting-examples-box { float: left; margin-right: 1.0em; }
		.setting-examples-box h4 { margin-top: 0; font-weight: normal; }
		</style>

		<table class="edit-form narrow" cellspacing="2" cellpadding="5">
		<tbody>
		<tr><th scope="row"><?php _e("Filter by date:"); ?></th>
		<td><label>Don&#8217;t syndicate items posted before:
		<input type="text" id="post-date-filter"
		name="post_date_filter"
		placeholder="<?php _e('timespan or date'); ?>"
		<?php if (strlen($limit['filter']) > 0) : ?>
		  value="<?php print esc_html($limit['filter']); ?>"
		<?php else : ?>
		  value="<?php _e('timespan or date'); ?>"
		<?php endif; ?>
		<?php if (count($inputClasses['filter']) > 0) : ?>
		  class="<?php print implode(" ", $inputClasses['filter']); ?>"
		<?php endif; ?>
		/></label>
		
		<?php
		foreach ($postMethods['filter'] as $callback) :
			$this->{$callback}($page, $limit['filter']);
		endforeach;
		?>

		<div class="setting-description hide-if-js">
		<div class="setting-examples-box">
		<h4>Examples:</h4>
		<ul>
		<li><code>7 days ago</code></li>
		<li><code>1 month ago</code></li>
		<li><code>January 1, 2010</code></li>
		</ul>
		</div>
		
		<p>Enter a timespan or a specific date, using
		<a href="http://us2.php.net/manual/en/function.strtotime.php"><code>strtotime()</code></a> format.
		Posts from before that date, or from dates older than that
		timespan, will not be syndicated.</p>
		<p>Leave blank <?php if ($page->for_feed_settings()) : ?>
		to use the site-wide default (currently:
		<code><?php print ((strlen($globalLimit['filter']) > 0) ? $globalLimit['filter'] :
		'no filtering') ?></code>)
		<?php else : ?>
		to turn off filtering.
		<?php endif; ?></p>
		</div>
		</td></tr>

		<tr>
		<th scope="row"><?php _e('Number of posts:'); ?></th>
		<td><p>When a feed contains new items, syndicate...</p>
		<?php
			$page->setting_radio_control(
				'post number sorter', 'post_number_sorter',
				$nfSelector, $nfParams
			);
		?></td>
		</tr>
		
		<tr><th scope="row"><?php _e("Expiration date:"); ?></th>
		<td><label>Syndicated posts expire
		<input type="text" id="post-expiration-date"
		name="post_expiration_date" size="10"
		placeholder="<?php _e('timespan'); ?>"
		<?php if (strlen($limit['expiration']) > 0) : ?>
		  value="<?php print esc_html($limit['expiration']); ?>"
		<?php else : ?>
		  value="<?php _e('timespan'); ?>"
		<?php endif; ?>
		<?php if (count($inputClasses['expiration']) > 0) : ?>
		  class="<?php print implode(" ", $inputClasses['expiration']); ?>"
		<?php endif; ?>
		/></label> <label>after they&#8217;re <select size="1" name="post_expiration_date_from">
		<option value="syndicated"<?php if ('syndicated'==$expireFrom): ?> selected="selected"<?php endif; ?>>first syndicated here</option>
		<option value="published"<?php if ('published'==$expireFrom): ?> selected="selected"<?php endif; ?>>originally published at the source</option>
		</select></label>
		
		<?php
		foreach ($postMethods['expiration'] as $callback) :
			$this->{$callback}($page, $limit['expiration']);
		endforeach;
		?>

		<div class="setting-description hide-if-js">
		<div class="setting-examples-box">
		<h4>Examples:</h4>
		<ul>
		<li><code>7 days</code></li>
		<li><code>1 month</code></li>
		<li><code>10 years</code></li>
		</ul>
		</div>
		
		<p>Enter a timespan, using
		<a href="http://us2.php.net/manual/en/function.strtotime.php"><code>strtotime()</code></a> format. Each syndicated post will be given that long to show up on the website; after the interval has passed, the posts will expire, and will no longer be displayed.</p>
		<p>Leave blank <?php if ($page->for_feed_settings()) : ?>
		to use the site-wide default (currently:
		<code><?php print ((strlen($globalLimit['expiration']) > 0) ? $globalLimit['expiration'] :
		'no expiration') ?></code>)
		<?php else : ?>
		to turn off post expiration.
		<?php endif; ?></p>
		</div>
		
		<h4 style="margin-top: 1.0em; font-weight: normal; clear: left;">When posts expire, they should be...</h4>
		<?php
		$page->setting_radio_control(
			'post expiration action', 'post_expiration_action',
			$peaSelector, $peaParams
		);
		?>
		</td></tr>
		<tr><th scope="row">Expired Featured Images:</th>
		<td><h4 style="margin-top:0px; font-weight: normal; clear: left">If an expired post is deleted from the system, and that post has a Featured Image set, what should be done with its Featured Image?</h4>
		<?php
		$page->setting_radio_control(
			'post expiration thumbnail', 'post_expiration_thumbnail',
			$petSelector, $petParams
		);
		?>
		</td></tr>
		
		<?php if (!$page->for_feed_settings()) : ?>
		<tr><th>Expiration queue:</th>
		<td><p><label>Process no more than <input name="fwplpbd_expiration_chunk" type="number" value="<?php print esc_attr(get_option('fwplpbd_expiration_chunk', 25)); ?>" min="1" max="256" /> expired posts per update cycle.</label></p>
		
		<p>To avoid seizing up system resources when there are many expired posts to process, this plugin will shuffle out a few posts at a time during FeedWordPress's normal update schedule. What's the maximum number of expired posts FeedWordPress should shuffle off during any given update cycle? (Recommended values: 10-25 for low-volume installations; 25-60 for higher-volume installations, or if you have a large backlog of older posts that you are trying to work through.)</p>
		</td></tr>
		<?php endif; ?>
		
		<tr>
		<th>Apply to existing <?php print $page->these_posts_phrase(); ?></th>
		<td><h4 style="margin-top: 0px; font-weight: normal; clear: left">You can apply this expiration-date setting retroactively to <?php print $page->these_posts_phrase(); ?> which have already been syndicated, using this button:</h4>
		<p><input type="submit" name="save[retroexpiration]" value="Apply retroactively" class="button" /></p>
		</td></tr>

		</table>
		<script type="text/javascript">
			jQuery('.timespan-field').focus( function () {
					jQuery(this).closest('table').find('.setting-description').slideUp();
					jQuery(this).closest('td').find('.setting-description').slideDown();
			} );
		</script>
		<?php
	} /* FWPLimitPostsByDate::display_settings () */
	
	function save_settings ($params, $page) {
		if (isset($params['save']) or isset($params['submit'])) :
			if (isset($params['post_date_filter'])) :
				if ($params['post_date_filter']==__('timespan or date')) :
					$params['post_date_filter'] = '';
				endif;
				if ($page->for_feed_settings()) :
					if (strlen($params['post_date_filter']) > 0) :
						$page->link->settings['post date filter'] = $params['post_date_filter'];
					else :
						unset($page->link->settings['post date filter']);
					endif;
					$page->link->save_settings(/*reload=*/ true);
				else :
					update_option("feedwordpress_post_date_filter", $params['post_date_filter']);
				endif;
			endif;

			if (isset($params['post_expiration_date'])) :
				if ($params['post_expiration_date']==__('timespan')) :
					$params['post_expiration_date'] = '';
				endif;
				
				if ($page->for_feed_settings()) :
					$page->link->settings['post expiration date'] = $params['post_expiration_date'];
					$page->link->settings['post expiration date from'] = $params['post_expiration_date_from'];
					
					if (strlen($params['post_expiration_action']) > 0
					and ('default' != $params['post_expiration_action'])) :
						$act = $params['post_expiration_action'];
						if ('redirect'==$params['post_expiration_action']) :
							$redir = $params['expired_post_redirect_to'];
						else :
							$redir = 'none';
						endif;
					
						$page->link->settings['post expiration action'] = $act;
						$page->link->settings['expired post redirect to'] = $redir;
					else :
						unset($page->link->settings['post expiration action']);
						unset($page->link->settings['expired post redirect to']);
					endif;
				else :
					update_option('feedwordpress_post_expiration_date', $params['post_expiration_date']);
					update_option('feedwordpress_post_expiration_date_from', $params['post_expiration_date_from']);
					if (strlen($params['post_expiration_action']) > 0) :
						$act = $params['post_expiration_action'];
						if ('redirect'==$params['post_expiration_action']) :
							$redir = $params['expired_post_redirect_to'];
						else :
							$redir = 'none';
						endif;

						update_option('feedwordpress_post_expiration_action', $act);
						update_option('feedwordpress_expired_post_redirect_to', $redir);
					else :
						delete_option('feedwordpress_post_expiration_action');
						delete_option('feedwordpress_expired_post_redirect_to');
					endif;
				endif;
			endif;
			
			if (isset($params['post_number_sorter'])) :
				$page->update_setting('post number sorter', $params['post_number_sorter']);
				if (isset($params['post_number_filter'][$params['post_number_sorter']])) :
					$N = $params['post_number_filter'][$params['post_number_sorter']];
				else :
					$N = NULL;
				endif;
				$page->update_setting('post number filter', $N);
			endif;
			
			if (isset($params['post_expiration_thumbnail'])) :
				$page->update_setting('post_expiration_thumbnail', $params['post_expiration_thumbnail']);
			endif;
			
			if (isset($params['fwplpbd_expiration_chunk'])) :
				$chunk = intval($params['fwplpbd_expiration_chunk']);
				
				// Default.
				if ($chunk < 1) : $chunk = 25; endif;
				
				update_option('fwplpbd_expiration_chunk', $chunk);
			endif;
			
			if (isset($params['save']) and is_array($params['save'])
			and array_key_exists('retroexpiration', $params['save'])) :
				// This could potentially be a pretty big set
				// to move through. So let's take it one chunk
				// at a time.
						
				$query_params[0] = array(
				'ignore_sticky_posts' => true,
				'post_type' => 'post',
				'post_status' => 'publish',
				'posts_per_page' => 25,
				'paged' => $pg,
				'meta_key' => 'syndication_feed_id',
				);
				
				if ($page->for_feed_settings()) :
					$query_params[0]['meta_value'] = $page->link->id;
					$span = $page->link->setting('post expiration date', 'post_expiration_date', NULL);
					$action = $page->link->setting('post expiration action', 'post_expiration_action', 'hide');
				else :
					$span = get_option('feedwordpress_post_expiration_date', NULL);
					$action = get_option('feedwordpress_post_expiration_action', 'hide');
				endif;
				$query_params[1] = $query_params[0];
				$query_params[1]['post_status'] = 'expired';

				$nPages = -1; $pg = 1;
				foreach ($query_params as $qp) :
					while (($nPages < 0) or ($pg <= $nPages)) : 
						$q = new WP_Query($qp);

						while ($q->have_posts()) :
							$q->the_post();
							
							// For now, there's no
							// good way to get
							// first-appearance date
							// So we just have to
							// use the UTC
							// publication date no
							// matter what....
							$basis = (int) get_post_time('U', /*gmt=*/ true, $q->post->ID);
						
							$ts = $this->to_ts($span, $basis, /*past bias=*/ false);
							if (!is_null($ts) and $ts > 0) :
								update_post_meta(
								$q->post->ID,
								'_syndication_expiration_date',
								$ts
								);
								update_post_meta(
								$q->post->ID,
								'_syndication_expiration_action',
								$action
								);
							else :
								delete_post_meta($q->post->ID, '_syndication_expiration_date');
								delete_post_meta($q->post->ID, '_syndication_expiration_action');
							endif;
							if (($q->post->post_status == 'expired') and ($ts > time())) :
								$old_status = $q->post->post_status;
								set_post_field('post_status', 'publish', $q->post->ID);
								wp_transition_post_status('publish', $old_status, $q->post);
							endif;
							FeedWordPress::diagnostic('expiration:mark', 'Previously syndicated post ['.esc_html($q->post->guid).'], status '.$q->post->post_status.' is marked to expire '.date('r', $ts));
						endwhile; // $q->have_posts()
						
						if (isset($q->max_num_pages)) :
							$nPages = intval($q->max_num_pages);
						endif;
						
						// Release me.
						$q = NULL;
						
						$pg++;
						$qp['paged'] = $pg;
												
					endwhile; // (($nPages < 0) or ($pg <= $nPages)) :
				endforeach; // $query_params as $qp
			endif;
		endif;
	} /* FWPLimitPostsByDate::save_settings () */
	
	function special_settings ($settings, $source) {
		return array_merge($settings, array(
		'post date filter',
		'post expiration date',
		'post expiration date from',
		'post number filter',
		'expired post redirect to',
		));
	} /* FWPLimitPostsByDate::special_settings () */
	
	function diagnostics ($diag, $page) {
		$diag['Syndicated Post Details']['expiration:mark'] = 'as posts are assigned expiration dates';
		$diag['Update Diagnostics']['expiration'] = 'as expired posts are removed';
		return $diag;
	}
	
	function to_ts ($limit, $basis = NULL, $past_bias = true) {
		$ts = NULL;
		if (is_null($basis)) : $basis = time(); endif;
		
		if (is_string($limit) and (strlen($limit) > 0)) :
			$oldLimit = $limit;
			if ($past_bias) :
				// Rather than trying to preg_match a relative date, let's just
				// presume that if we ended up with a future date, it was
				// because of a relative date, and last/ago that sucker.
				// So that, e.g., "Sunday" becomes "last Sunday"; "5 days"
				// becomes "5 days ago."
				foreach (array('last %s','%s ago','%s days ago') as $relative) :
					if ((strtotime($limit, $basis)>time()) or (strtotime($limit, $basis) < 1)) :
						$limit = sprintf($relative, $oldLimit);
					endif;
				endforeach;
			endif;
			
			if (strtotime($limit, $basis) > 0) :
				$ts = strtotime($limit, $basis);
			elseif (strtotime($oldLimit, $basis) > 0) :
				$ts = strtotime($oldLimit, $basis);
			endif;
		endif;
		return $ts;
	} /* FWPLimitPostsByDate::to_ts() */

	function syndicated_item_sort_latest_first ($a, $b) {
		$aDate = (int) $a->get_date('U');
		$bDate = (int) $b->get_date('U');
		return (
			($aDate == $bDate) ? 0
			: (($aDate > $bDate) ? -1
			: 1)
		);
	}

	function syndicated_item_sort_earliest_first ($a, $b) {
		return $this->syndicated_item_sort_latest_first($b, $a); // invert!
	}

	var $new_items;
	function syndicated_feed_items ($posts, $feed) {
		$this->new_items = 0; // reset the counter

		$method = $feed->setting('post number sorter', 'post_number_sorter', NULL);
		switch ($method) :
		case 'rand':
			shuffle($posts);
			break;
		case 'latest_first':
		case 'earliest_first':
			usort($posts, array(&$this, "syndicated_item_sort_{$method}"));
			break;
		default:
			// NOOP
		endswitch;
		return $posts;
	}

	function syndicated_item ($item, $post) {
		$limit = $post->link->setting('post date filter', 'post_date_filter', NULL);
		
		if (!is_null($limit)) :
			// In error conditions, we filter nothing, because the
			// -1 should always be <= the published date
			if ($this->to_ts($limit) > $post->published()) :
				$item = NULL;
			endif;
		endif;
		return $item;
	} /* FWPLimitPostsByDate::syndicated_item() */

	function syndicated_post ($data, $post) {
		if ($post->freshness() < 2) : // been there, done that
			// NOOP
		else :
			$this->new_items = $this->new_items + 1;
			$filtered = $post->link->setting('post number sorter', 'post_number_sorter', NULL);
			if (in_array($filtered, array('rand', 'latest_first', 'earliest_first'))) :
				$ceiling = (int) $post->link->setting('post number filter', 'post_number_filter', 5);
				if ($this->new_items > $ceiling) :
					$data['post_type'] = 'syndicated_skipped';
				endif;
			endif;
			
			$timespan = $post->link->setting('post expiration date', 'post_expiration_date', NULL);
			if (('syndicated_skipped' != $data['post_type']) and $timespan) :
				if ('published'==$post->link->setting('post expiration date from', 'post_expiration_date_from', 'syndicated')) :
					$basis = $post->published();
				else :
					$basis = time();
				endif;
				
				$ts = $this->to_ts($timespan, $basis, /*past bias=*/ false);
				if (!is_null($ts) and $ts > 0) :
					FeedWordPress::diagnostic('expiration:mark', 'Item ['.esc_html($post->guid()).'] is marked to expire '.date('r', $ts));
					$data['meta']['_syndication_expiration_date'] = $ts;
					$data['meta']['_syndication_expiration_action'] = $post->link->setting('post expiration action', 'post_expiration_action', 'hide');
				endif;
			endif;
		endif;

		return $data;
	} /* FWPLimitPostsByDate::syndicated_post() */

	function process_expirations ($delta) {
		global $post;
		global $wpdb;
		
		$q = new WP_Query(array(
		'post_type' => 'post',
		'post_status' => 'publish',
		'posts_per_page' => get_option('fwplpbd_expiration_chunk', 25),
		// Let's make sure this stays safe, sane and consensual. If
		// there's a lot more to check, we'll pick them up on the next
		// go-round.
		
		'meta_key' => '_syndication_expiration_date',
		'meta_value' => time(),
		'meta_compare' => '<=',
		 // This is string comparison, which is sick and wrong, but it
		 // will work well enough as a filter if we double-check the
		 // results.
		));
		while ($q->have_posts()) : $q->the_post();
			$expiration = get_post_meta($post->ID, '_syndication_expiration_date', /*single=*/ true);
			if ((int) $expiration and ((int) $expiration <= time())) :
				$action = get_post_meta($post->ID, '_syndication_expiration_action', /*single=*/ true);

				FeedWordPress::diagnostic('expiration', 'Post ['.$post->ID.'] "'.esc_html($post->post_title).'" expired as of '.date('r', (int) $expiration).' and will now be '.(('trash'==$action)?'trashed':(('nuke'==$action)?'deleted permanently':'hidden')));

				switch ($action) :
				case 'trash':
				case 'nuke':
					$feed = get_syndication_feed_object($post->ID);
					
					$thumbId = get_post_thumbnail_id($post->ID);
					
					wp_delete_post(
						/*postid=*/ $post->ID,
						/*force delete=*/ ('nuke'==$action)
					);
					
					
					// Check to see whether any other posts
					// use this as a Featured Image. If not
					// then zap it.
					if ("nuke"==$feed->setting('post expiration thumbnail', 'post_expiration_thumbnail', "keep")) :
						if (strlen($thumbId) > 0) :
							$qrows = $wpdb->get_results($wpdb->prepare("
							SELECT meta_value FROM $wpdb->postmeta
							WHERE meta_key = '_thumbnail_id'
							AND meta_value = '%d'
							AND post_id <> '%d'
							", $thumbId, $post->ID));
						
							if (count($qrows) < 1) :
								FeedWordPress::diagnostic('expiration', 'The expired post ['.$post->ID.']  had an attached Featured Image, which is not used as the Featured Image for any other post. We will now clean up and the image will be '.(('trash'==$action)?'trashed':(('nuke'==$action)?'deleted permanently':'hidden')));
								
								wp_delete_attachment(
								/*id=*/ $thumbId,
								/*force delete=*/ 'nuke'==$action
								);
							else :
								FeedWordPress::diagnostic('expiration', 'The expired post ['.$post->ID.']  had an attached Featured Image, but it CANNOT be deleted right now because at least one other post uses the same image as its Featured Image.');

							endif;
						endif;

					endif;
					break;
				case 'hide':
				case 'redirect':
				default:
					$old_status = $post->post_status;
					set_post_field('post_status', 'expired', $post->ID);
					wp_transition_post_status('expired', $old_status, $post);
					break;
				endswitch;
			endif;
		endwhile;
	} /* FWPLimitPostsByDate::process_expirations () */
} /* class FWPLimitPostsByDate */


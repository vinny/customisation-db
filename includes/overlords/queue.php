<?php
/**
*
* @package Titania
* @copyright (c) 2008 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License, version 2
*
*/

/**
* @ignore
*/
if (!defined('IN_TITANIA'))
{
	exit;
}

class queue_overlord
{
	/**
	* Queue array
	* Stores [id] => row
	*
	* @var array
	*/
	public static $queue = array();

	public static $sort_by = array(
		't' => array('SUBMIT_TIME', 'q.queue_submit_time', true),
	);

	/**
	* Load queue(s) from queue id(s)
	*
	* @param int|array $queue_id queue_id or an array of queue_ids
	*/
	public static function load_queue($queue_id)
	{
		if (!is_array($queue_id))
		{
			$queue_id = array($queue_id);
		}

		// Only get the rows for those we have not gotten already
		$queue_id = array_diff($queue_id, array_keys(self::$queue));

		if (!sizeof($queue_id))
		{
			return;
		}

		$sql_ary = array(
			'SELECT' => 'q.*',

			'FROM'		=> array(
				TITANIA_QUEUE_TABLE	=> 'q',
			),

			'WHERE' => phpbb::$db->sql_in_set('q.queue_id', array_map('intval', $queue_id))
		);

		$sql = phpbb::$db->sql_build_query('SELECT', $sql_ary);

		$result = phpbb::$db->sql_query($sql);

		while($row = phpbb::$db->sql_fetchrow($result))
		{
			self::$queue[$row['queue_id']] = $row;
		}

		phpbb::$db->sql_freeresult($result);
	}

	public static function get_queue_object($queue_id, $query = false)
	{
		if (!isset(self::$queue[$queue_id]) && $query)
		{
			self::load_queue($queue_id);
		}

		if (!isset(self::$queue[$queue_id]))
		{
			return false;
		}

		$queue = new titania_queue;
		$queue->__set_array(self::$queue[$queue_id]);

		return $queue;
	}

	/**
	* Display forum-like list for queue
	*
	* @param string $type The type of queue (the contrib type)
	* @param object|boolean $sort The sort object (includes/tools/sort.php)
	*/
	public static function display_queue($type, $queue_status = TITANIA_QUEUE_NEW, $sort = false)
	{
		if ($sort === false)
		{
			// Setup the sort tool
			$sort = self::build_sort();
		}
		$sort->request();

		$controller_helper = phpbb::$container->get('phpbb.titania.controller.helper');
		$path_helper = phpbb::$container->get('path_helper');
		$queue_ids = array();

		$sql_ary = array(
			'SELECT' => 'q.*, c.*, r.*, t.*, u.username as topic_first_post_username, u.user_colour as topic_first_post_user_colour, ul.username as topic_last_post_username, ul.user_colour as topic_last_post_user_colour, tp.topic_posted',

			'FROM'		=> array(
				TITANIA_QUEUE_TABLE		=> 'q',
				TITANIA_CONTRIBS_TABLE	=> 'c',
				TITANIA_REVISIONS_TABLE	=> 'r',
				TITANIA_TOPICS_TABLE	=> 't',
			),

			'LEFT_JOIN'	=> array(
				array(
					'FROM'	=> array(TITANIA_TOPICS_POSTED_TABLE => 'tp'),
					'ON'	=> 'tp.topic_id = t.topic_id AND tp.user_id = ' . (int) phpbb::$user->data['user_id'],
				),
			),

			'WHERE' => 'q.queue_type = ' . (int) $type .
				(($queue_status) ? ' AND q.queue_status = ' . (int) $queue_status : ' AND q.queue_status > 0 ') . '
				AND c.contrib_id = q.contrib_id
				AND r.revision_id = q.revision_id
				AND t.topic_id = q.queue_topic_id',

			'ORDER_BY'	=> $sort->get_order_by(),
		);

		$sql_ary['LEFT_JOIN'][] = array(
			'FROM'	=> array(USERS_TABLE => 'u'),
			'ON'	=> 't.topic_first_post_user_id = u.user_id',
		);

		$sql_ary['LEFT_JOIN'][] = array(
			'FROM'	=> array(USERS_TABLE => 'ul'),
			'ON'	=> 't.topic_last_post_user_id = ul.user_id',
		);

		titania_tracking::get_track_sql($sql_ary, TITANIA_TOPIC, 't.topic_id');

		// Main SQL Query
		$sql = phpbb::$db->sql_build_query('SELECT', $sql_ary);

		// Handle pagination
		if (!$sort->sql_count($sql_ary, 'q.queue_id'))
		{
			// No results...no need to query more...
			return;
		}

		$url_parts = $path_helper->get_url_parts($controller_helper->get_current_url());
		$sort->build_pagination($url_parts['bases'], $url_parts['params']);

		$queue_ids = $user_ids = array();

		// Get the data
		$result = phpbb::$db->sql_query_limit($sql, $sort->limit, $sort->start);

		while ($row = phpbb::$db->sql_fetchrow($result))
		{
			// Store the tracking info we grabbed from the DB
			titania_tracking::store_from_db($row);

			$queue_ids[] = $row['queue_id'];
			$user_ids[] = $row['topic_first_post_user_id'];
			$user_ids[] = $row['topic_last_post_user_id'];
			$user_ids[] = $row['submitter_user_id'];

			self::$queue[$row['queue_id']] = $row;
		}
		phpbb::$db->sql_freeresult($result);

		users_overlord::load_users($user_ids);

		$topic = new titania_topic;

		foreach ($queue_ids as $queue_id)
		{
			$row = self::$queue[$queue_id];

			$topic->__set_array($row);
			$topic->topic_posted = $row['topic_posted'];

			phpbb::$template->assign_block_vars('topics', array_merge($topic->assign_details(), array(
				'TOPIC_SUBJECT'				=> $row['contrib_name'] . ' - ' . $row['revision_version'],
				'S_TOPIC_PROGRESS'			=> ($row['queue_progress']) ? true : false,
				'U_VIEW_TOPIC'				=> $topic->get_url(false, array('tag' => $queue_status)),
				'S_TESTED'					=> ($row['queue_tested']) ? true : false,
			)));
		}

		unset($topic);

		phpbb::$template->assign_vars(array(
			'S_TOPIC_LIST'		=> true,
		));

		// Assign common stuff for topics list
		topics_overlord::assign_common();
	}

	/**
	* Display the complete queue item (includes the topic)
	*
	* @param int $queue_id
	* @return array data from display_queue_item
	*/
	public static function display_queue_item_complete($queue_id)
	{
		$data = self::display_queue_item($queue_id);

		// Display the posts
		posts_overlord::display_topic_complete($data['topic']);

		return $data;
	}

	/**
	* Display a single queue item
	*
	* @param int $queue_id
	* @return array('row' => (sql selection), 'contrib' => $contrib, 'topic' => $topic)
	*/
	public static function display_queue_item($queue_id)
	{
		titania::add_lang('contributions');
		$controller_helper = phpbb::$container->get('phpbb.titania.controller.helper');
		$path_helper = phpbb::$container->get('path_helper');

		$sql_ary = array(
			'SELECT' => '*',

			'FROM'		=> array(
				TITANIA_QUEUE_TABLE		=> 'q',
				TITANIA_REVISIONS_TABLE	=> 'r',
				TITANIA_TOPICS_TABLE	=> 't',
			),

			'WHERE' => 'q.queue_id = ' . (int) $queue_id . '
				AND r.revision_id = q.revision_id
				AND t.topic_id = q.queue_topic_id',
		);

		// Main SQL Query
		$sql = phpbb::$db->sql_build_query('SELECT', $sql_ary);
		$result = phpbb::$db->sql_query($sql);
		$row = phpbb::$db->sql_fetchrow($result);
		phpbb::$db->sql_freeresult($result);

		if (!$row)
		{
			trigger_error('NO_QUEUE_ITEM');
		}

		self::$queue[$queue_id] = $row;
		$queue = self::get_queue_object($queue_id);

		// Load the contribution
		$contrib = new titania_contribution();
		$contrib->load((int) $row['contrib_id']);
		$contrib->get_download($row['revision_id']);
		$contrib->get_revisions();
		$contrib->get_screenshots();
		$contrib->assign_details();

		// Load the topic (with the already selected data)
		$topic = new titania_topic;
		$topic->__set_array($row);

		// Bit of a hack for the posting
		phpbb::$request->overwrite('t', $topic->topic_id);

		$is_moderator = $contrib->type->acl_get('moderate');
		$is_validator = $contrib->type->acl_get('validate');

		// Misc actions
		$misc_actions = array(
			array(
				'RETEST_MPV',
				$queue->get_tool_url('mpv', $row['revision_id']),
				$contrib->type->mpv_test,
			),
			array(
				'RETEST_AUTOMOD',
				$queue->get_tool_url('automod', $row['revision_id']),
				$contrib->type->automod_test,
			),
		);

		// Some quick-actions
		$quick_actions = array();

		if ($row['queue_status'] > 0)
		{
			$misc_actions = array_merge($misc_actions, array(
				array(
					'REBUILD_FIRST_POST',
					$queue->get_url('rebuild'),
					true,
				),
				array(
					'ALLOW_AUTHOR_REPACK',
					$queue->get_url('allow_author_repack'),
					$is_moderator && !$row['allow_author_repack'],
				),
				array(
					'MARK_TESTED',
					$queue->get_url('tested'),
					!$row['queue_tested'],
				),
				array(
					'MARK_UNTESTED',
					$queue->get_url('not_tested'),
					$row['queue_tested'],
				),
			));

			$tags = titania::$cache->get_tags(TITANIA_QUEUE);
			unset($tags[$row['queue_status']]);

			$quick_actions = array(
				array(
					'MARK_NO_PROGRESS',
					$queue->get_url('no_progress'),
					$row['queue_progress'] == phpbb::$user->data['user_id'],
					'queue_progress',
				),
				array(
					'MARK_IN_PROGRESS',
					$queue->get_url('in_progress'),
					!$row['queue_progress'],
					'queue_progress',
				),
				array(
					'CHANGE_STATUS',
					$queue->get_url('move'),
					true,
					'change_status',
					$tags,
				),
				array(
					'REPACK',
					$contrib->get_url('revision', array('page' => 'repack', 'id' => $row['revision_id'])),
					$is_moderator,
					'repack',
				),
				array(
					'CAT_MISC',
					'',
					true,
					'misc',
					'',
					$misc_actions,
				),
				array(
					'APPROVE',
					$queue->get_url('approve'),
					$is_validator,
					'approve',
				),
				array(
					'DENY',
					$queue->get_url('deny'),
					$is_validator,
					'deny',
				),
			);
		}

		if (empty($quick_actions) && !empty($misc_actions))
		{
			$quick_actions = array(
				array(
					'CAT_MISC',
					'',
					true,
					'misc',
					'',
					$misc_actions
				),
			);	
		}

		foreach ($quick_actions as $data)
		{
			$properties = array('name', 'url', 'auth', 'class', 'tags', 'subactions');
			$data = array_pad($data, sizeof($properties), '');
			$data = array_combine($properties, $data);

			if (!$data['auth'])
			{
				continue;
			}
			
			phpbb::$template->assign_block_vars('queue_actions', array(
				'NAME'		=> phpbb::$user->lang($data['name']),
				'CLASS'		=> $data['class'],
				'U_VIEW'	=> $data['url'],
			));

			if ($data['tags'])
			{
				foreach ($data['tags'] as $tag_id => $tag_row)
				{
					phpbb::$template->assign_block_vars('queue_actions.subactions', array(
						'ID'		=> $tag_id,
						'NAME'		=> phpbb::$user->lang($tag_row['tag_field_name']),

						'U_ACTION'	=> $path_helper->append_url_params($data['url'], array(
							'id'	=> $tag_id,
							'hash'	=> generate_link_hash('quick_actions'),
						)),
					));
				}
			}

			if ($data['subactions'])
			{
				foreach ($data['subactions'] as $subdata)
				{
					$subdata = array_pad($subdata, sizeof($properties), '');
					$subdata = array_combine($properties, $subdata);

					if (!$subdata['auth'])
					{
						continue;
					}
					phpbb::$template->assign_block_vars('queue_actions.subactions', array(
						'NAME'		=> phpbb::$user->lang($subdata['name']),
						'U_ACTION'	=> $subdata['url'],
					));
				}
			}
		}

		if ($row['queue_status'] == -2)
		{
			$current_status = phpbb::$user->lang['REVISION_DENIED'];
		}
		else if ($row['queue_status'] == -1)
		{
			$current_status = phpbb::$user->lang['REVISION_APPROVED'];
		}
		else
		{
			$current_status = titania_tags::get_tag_name($row['queue_status']);
		}
		phpbb::$template->assign_vars(array(
			'CURRENT_STATUS'			=> $current_status,

			'S_DISPLAY_CONTRIBUTION'	=> true,
			'S_IN_QUEUE'				=> true,

			'U_POST_REPLY'				=> $queue->get_url('reply'),
			'U_NEW_REVISION'			=> false, // Prevent nubs from trying to submit a new revision when they want to actually repack
		));

		// Subscriptions
		titania_subscriptions::handle_subscriptions(
			TITANIA_TOPIC,
			$topic->topic_id,
			$controller_helper->get_current_url(),
			'SUBSCRIBE_TOPIC'
		);

		return compact('row', 'contrib', 'topic');
	}

	/**
	* Display the categories (tags)
	*
	* @param int $type
	*/
	public static function display_categories($type, $selected = false)
	{
		$tags = titania::$cache->get_tags(TITANIA_QUEUE);
		$tag_count = array();
		$total = 0;
		$controller_helper = phpbb::$container->get('phpbb.titania.controller.helper');
		$type_url = titania_types::$types[$type]->url;

		$sql = 'SELECT queue_status, COUNT(queue_id) AS cnt FROM ' . TITANIA_QUEUE_TABLE . '
			WHERE queue_type = ' . (int) $type . '
			GROUP BY queue_status';
		$result = phpbb::$db->sql_query($sql);
		while ($row = phpbb::$db->sql_fetchrow($result))
		{
			$total += ($row['queue_status'] > 0) ? $row['cnt'] : 0;
			$tag_count[$row['queue_status']] = $row['cnt'];
		}
		phpbb::$db->sql_freeresult($result);

		phpbb::$template->assign_block_vars('queue_tags', array(
			'TAG_NAME'		=> phpbb::$user->lang['ALL'],
			'TAG_COUNT'		=> $total,
			'U_VIEW_TAG'	=> $controller_helper->route('phpbb.titania.queue.type', array('queue_type' => $type_url, 'tag' => 'all')),
			'S_SELECTED'	=> ($selected == 0) ? true : false,
		));

		foreach ($tags as $tag_id => $row)
		{
			if (!isset($tag_count[$tag_id]))
			{
				// Hide empty ones
				continue;
			}

			phpbb::$template->assign_block_vars('queue_tags', array(
				'TAG_NAME'		=> (isset(phpbb::$user->lang[$row['tag_field_name']])) ? phpbb::$user->lang[$row['tag_field_name']] : $row['tag_field_name'],
				'TAG_COUNT'		=> $tag_count[$tag_id],
				'U_VIEW_TAG'	=> $controller_helper->route('phpbb.titania.queue.type', array('queue_type' => $type_url, 'tag' => $tag_id)),
				'S_SELECTED'	=> ($selected == $tag_id) ? true : false,
			));
		}
	}

	/**
	* Generate the stats page
	*/
	public function display_stats()
	{
		$stats = false;//titania::$cache->get('queue_stats');

		if ($stats === false)
		{
			$stats = array();

			foreach (titania_types::$types as $type_id => $class)
			{
				foreach (titania::$config->queue_stats_periods as $name => $data)
				{
					// Shorten
					$temp_stats = array();

					// Select the stats for this type
					$sql = 'SELECT revision_id, contrib_id, queue_type, submitter_user_id, queue_submit_time, queue_close_time, queue_close_user
						FROM ' . TITANIA_QUEUE_TABLE . '
							WHERE queue_type = ' . (int) $type_id . '
								AND queue_close_time > 0 ' .
								((isset($data['where'])) ? 'AND ' . $data['where'] : '');
					$result = phpbb::$db->sql_query($sql);
					while ($row = phpbb::$db->sql_fetchrow($result))
					{
						// List of submitters with totals
						$temp_stats['submitters'][$row['submitter_user_id']] = (isset($temp_stats['submitters'][$row['submitter_user_id']])) ? $temp_stats['submitters'][$row['submitter_user_id']] + 1 : 1;

						// List of users who closed the queue items with totals
						$temp_stats['closers'][$row['queue_close_user']] = (isset($temp_stats['closers'][$row['queue_close_user']])) ? $temp_stats['closers'][$row['queue_close_user']] + 1 : 1;

						// Count
						$temp_stats['total'] = (isset($temp_stats['total'])) ? $temp_stats['total'] + 1 : 1;

						// Total time in validation
						$temp_stats['total_validation_time'] = (isset($temp_stats['total_validation_time'])) ? $temp_stats['total_validation_time'] + ($row['queue_close_time'] - $row['queue_submit_time']): ($row['queue_close_time'] - $row['queue_submit_time']);
					}
					phpbb::$db->sql_freeresult($result);

					// Shorten
					$stats[$type_id][$name] = $temp_stats;
				}

				// Handle the data
				foreach (titania::$config->queue_stats_periods as $name => $data)
				{
					// Shorten
					$temp_stats = $stats[$type_id][$name];

					// List of submitters with totals
					if (isset($temp_stats['submitters']) && sizeof($temp_stats['submitters']))
					{
						arsort($temp_stats['submitters']);
					}

					// List of users who closed the queue items with totals
					if (isset($temp_stats['closers']) && sizeof($temp_stats['closers']))
					{
						arsort($temp_stats['closers']);
					}

					// Average time in validation
					$temp_stats['average_validation_time'] = floor((isset($temp_stats['total_validation_time']) && $temp_stats['total_validation_time'] > 0) ? $temp_stats['total_validation_time'] / $temp_stats['total'] : 0);

					// Shorten
					$stats[$type_id][$name] = $temp_stats;
				}
			}

			titania::$cache->put('queue_stats', $stats, (60 * 60));
		}

		// Need to grab some user data
		$user_ids = array();
		foreach (titania_types::$types as $type_id => $class)
		{
			foreach (titania::$config->queue_stats_periods as $name => $data)
			{
				// Shorten
				$temp_stats = $stats[$type_id][$name];

				foreach (array('submitters', 'closers') as $type)
				{
					if (isset($temp_stats[$type]) && sizeof($temp_stats[$type]))
					{
						$i = 1;
						foreach ($temp_stats[$type] as $user_id => $cnt)
						{
							// Only grab the first 5
							if ($i > 5)
							{
								break;
							}
							$i++;

							$user_ids[] = $user_id;
						}
					}
				}
			}
		}

		// Load the users
		users_overlord::load_users($user_ids);

		// Output
		foreach (titania_types::$types as $type_id => $class)
		{
			phpbb::$template->assign_block_vars('stats', array(
				'TITLE'		=> $class->lang,
			));

			foreach (titania::$config->queue_stats_periods as $name => $data)
			{
				// Shorten
				$temp_stats = $stats[$type_id][$name];

				$avg_num_weeks = $avg_num_days = 0;
				if ($temp_stats['average_validation_time'] > 0)
				{
					$avg_num_weeks = floor($temp_stats['average_validation_time'] / (60 * 60 * 24 * 7));
					$avg_num_days = floor($temp_stats['average_validation_time'] / (60 * 60 * 24)) %7;
				}

				phpbb::$template->assign_block_vars('stats.periods', array(
					'TITLE'		=> (isset(phpbb::$user->lang[$data['lang']])) ? phpbb::$user->lang[$data['lang']] : $data['lang'],

					'AVERAGE_VALIDATION_TIME'	=> phpbb::$user->lang('NUM_WEEKS', $avg_num_weeks) . ' ' . phpbb::$user->lang('NUM_DAYS', $avg_num_days),
				));

				// Submitter/closer data
				foreach (array('submitters', 'closers') as $type)
				{
					if (isset($temp_stats[$type]) && sizeof($temp_stats[$type]))
					{
						$i = 1;
						foreach ($temp_stats[$type] as $user_id => $cnt)
						{
							// Only output the first 5
							if ($i > 5)
							{
								break;
							}
							$i++;

							// Assign user details and total
							phpbb::$template->assign_block_vars('stats.periods.' . $type, array_merge(
								users_overlord::assign_details($user_id), array(
								'TOTAL'		=> $cnt,
							)));
						}
					}
				}
			}
		}
	}

	/**
	* Generate topic status
	*/
	public static function folder_img($is_unread, &$folder_img, &$folder_alt, $replies = 0)
	{
		titania::_include('functions_display', 'titania_topic_folder_img');

		titania_topic_folder_img($folder_img, $folder_alt, $replies, $is_unread);
	}

	/**
	* Setup the sort tool and return it for posts display
	*
	* @return titania_sort
	*/
	public static function build_sort()
	{
		// Setup the sort and set the sort keys
		$sort = new titania_sort();
		$sort->set_sort_keys(self::$sort_by);

		$sort->set_defaults(phpbb::$config['topics_per_page']);

		return $sort;
	}
}
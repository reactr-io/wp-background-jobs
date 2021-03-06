<?php

declare(ticks=5);

namespace ReactrIO\Background;

use RuntimeException;
use stdClass;
use WP_Post;

class E_UnregisteredJobType extends RuntimeException {};
class E_DequeueJob extends RuntimeException {};
class E_SaveJob extends RuntimeException {};

abstract class Job
{
    const POST_TYPE = 'reactr-bg-job';
    const STATUS_UNQUEUED='unqueued';
    const STATUS_QUEUED = 'draft';
    const STATUS_DONE = 'publish';
    const STATUS_IN_PROGRESS='pending';
    const STATUS_FAILED='private';
    const STATUS_ABANDONED='trash';

    static protected $_registered_types = [];

    /** @property int $_id The post ID for the Job, if saved */
    protected $_id = 0;

    /** @property int $_parent_job_id The ID of the parent job which created this job */
    protected $_parent_job_id = 0;

    /** @property string $_label A human-friendly label to describe the job */
    protected $_label = '';

    /** @property array $_dataset A dataset for the job to work with */
    protected $_dataset = [];

    /** @property string[] $_history A history log of what has happened with the job */
    protected $_history = [];

    /** @property string[] $_output A log of the Job's output */
    protected $_output = [];

    /** @property string $queue The name of the queue */
    protected $_queue = '';

    /** @property int $_worker_id Returns the ID of the worker assigned this Job */
    protected $_worker_id = '';

    /** @property int $time_estimate The estimated time for this job to complete */
    protected $_time_estimate = 20;

    /** @property int $_retry_i Current retry iteration for failed jobs */
    protected $_retry_i = 0;

    /** @property int $_max_retries Number of retry attempts to make before abandoning */
    protected $_max_retries = 0;

    /** @property $_status int The status of the job */
    protected $_status = self::STATUS_UNQUEUED;

    protected function __construct(array $props)
    {
        foreach ($props as $k => $v) {
            $this->$k = $v;
        }
    }

    /**
     * Runs the job
     * @return Job
     */
    abstract function run();

    /**
     * Registers a type of Job with a class implementation
     *
     * @param string $type_name
     * @param string $klass
     * @return string
     */
    static function register_type($type_name, $klass)
    {
        self::$_registered_types[$type_name] = $klass;
        return $type_name;
    }

    /**
     * Deregisters a job type
     *
     * @param string $type_name
     * @return string
     */
    static function deregister_type($type_name)
    {
        unset(self::$_registered_types[$type_name]);
        return $type_name;
    }

    /**
     * Registers the post type
     *
     * @return \WP_Post_Type
     */
    static function register_post_type()
    {
        $labels = array(
            'name'                  => _x('Jobs', 'Post Type General Name', 'reactr-bg'),
            'singular_name'         => _x('Job', 'Post Type Singular Name', 'reactr-bg'),
            'menu_name'             => __('Background Jobs', 'reactr-bg'),
            'name_admin_bar'        => __('Background Jobs', 'reactr-bg'),
            'archives'              => __('Job Archives', 'reactr-bg'),
            'attributes'            => __('Job Attributes', 'reactr-bg'),
            'parent_item_colon'     => __('Parent Job:', 'reactr-bg'),
            'all_items'             => __('All Jobs', 'reactr-bg'),
            'add_new_item'          => __('Add New Job', 'reactr-bg'),
            'add_new'               => __('Add New', 'reactr-bg'),
            'new_item'              => __('New Job', 'reactr-bg'),
            'edit_item'             => __('Edit Job', 'reactr-bg'),
            'update_item'           => __('Update Job', 'reactr-bg'),
            'view_item'             => __('View Job', 'reactr-bg'),
            'view_items'            => __('View Jobs', 'reactr-bg'),
            'search_items'          => __('Search Job', 'reactr-bg'),
            'not_found'             => __('Not found', 'reactr-bg'),
            'not_found_in_trash'    => __('Not found in Trash', 'reactr-bg'),
            'featured_image'        => __('Featured Image', 'reactr-bg'),
            'set_featured_image'    => __('Set featured image', 'reactr-bg'),
            'remove_featured_image' => __('Remove featured image', 'reactr-bg'),
            'use_featured_image'    => __('Use as featured image', 'reactr-bg'),
            'insert_into_item'      => __('Insert into Job', 'reactr-bg'),
            'uploaded_to_this_item' => __('Uploaded to this job', 'reactr-bg'),
            'items_list'            => __('Jobs list', 'reactr-bg'),
            'items_list_navigation' => __('Jobs list navigation', 'reactr-bg'),
            'filter_items_list'     => __('Filter job list', 'reactr-bg'),
        );

        $args = array(
            'label'                 => __('Job', 'reactr-bg'),
            'description'           => __('A job that will be processed in the background', 'reactr-bg'),
            'labels'                => $labels,
            'supports'              => ['title'],
            'hierarchical'          => true,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 5,
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => true,
            'can_export'            => true,
            'has_archive'           => true,
            'exclude_from_search'   => false,
            'publicly_queryable'    => false,
            'capability_type'       => 'page',
            'show_in_rest'          => true,
        );

        return register_post_type('reactr-bg-job', $args);
    }

    /**
     * @param string $queue
     * @param array $statuses
     * @return int
     */
    static function get_count_from_queue($queue, $statuses = [self::STATUS_QUEUED])
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        // TODO: implement $queue
        $query = $wpdb->prepare(
            "SELECT COUNT(`ID`)
                    FROM `{$wpdb->posts}`
                    WHERE `post_type` = %s",
            self::POST_TYPE
        );

        if (is_array($statuses) && !empty($statuses))
        {
            $mime_part = rtrim(str_repeat('%s,', count($statuses)), ',');
            $query .= $wpdb->prepare(
                " AND `post_mime_type` IN ({$mime_part})",
                $statuses
            );
        }

        $result = $wpdb->get_col($query);
        return reset($result);
    }

    /**
     * Gets a job from the queue
     * 
     * By default, only jobs which are in the 'queued' or 'failed' state are retrieved
     * 
     * @param string $queue
     * @param int $limit number of jobs to return
     * @param string[]|string $statuses
     * @param int|null $parent_id find child tasks with this parent_id set
     * @return Job|Job[]
     */
    static function get_all_from_queue($queue = NULL, $limit = 0, $statuses = [self::STATUS_FAILED, self::STATUS_QUEUED], $parent_id = NULL)
    {
        $query_params = [
            'post_type'   => self::POST_TYPE,
            'post_status' => $statuses
        ];

        // Was a queue specified?
        if ($queue)
            $query_params['post_mime_type'] = "queue/{$queue}";

        if (!is_null($parent_id))
            $query_params['post_parent'] = $parent_id;

        // Was a limit given?
        if ($limit)
            $limit = intval($limit);
        if ($limit > 0)
            add_filter('post_limits_request', function() use ($limit) {
                return "LIMIT {$limit}";
            });

        $query  = new \WP_Query($query_params);
        $retval = array_map([self::class, 'from_post'], $query->get_posts());

        if ($limit > 0)
            remove_all_filters('post_limits_request');

        if (count($retval) === 1)
            $retval = $retval[0];

        return $retval;
    }

    /**
     * Gets the next available job for processing from the provided queue.
     * 
     * If no queue is provided, then a job from any queue will be returned.
     *
     * @param null|string $queue
     * @return null|self
     */
    static function get_next_from_queue($queue = NULL)
    {
        $statuses = [self::STATUS_FAILED, self::STATUS_QUEUED];
        $parent = self::get_all_from_queue($queue, 1, $statuses, 0);

        if ($parent)
            $job = self::search_for_next_child_in_queue($parent, $queue);
        else
            // Just in case: find any possible remaining tasks / orphans
            $job = self::get_all_from_queue($queue, 1, $statuses, NULL);

        if ($job)
            return $job->save($job->get_queue_name());
        else
            return NULL;
    }

    /**
     * @param self $parent
     * @param string|null $queue
     * @return self
     */
    static function search_for_next_child_in_queue($parent, $queue = NULL)
    {
        $statuses = [self::STATUS_FAILED, self::STATUS_QUEUED];

        $job = self::get_all_from_queue($queue, 1, $statuses, $parent->get_id());

        if (!$job)
            return $parent;
        else
            return self::search_for_next_child_in_queue($job, $queue);
    }

    /**
     * Dequeue a job from the queue
     */
    static function dequeue($job_id)
    {
        $job = NULL;
        if (($post = get_post($job_id)))
        {
            $job = self::from_post($post);
            if (wp_delete_post($job_id))
            {
                $job->_id = NULL;
                $job->_queue = NULL;
                $job->_worker_id = NULL;
            }
        }

        if (!$job)
            throw new E_DequeueJob("Job #{$job_id} could not be dequeued");

        return $job;
    }

    /**
     * Gets the name of all queues.
     * 
     * By default, we show all queues that have ever been used, but by specifying TRUE
     * as the first argument, only queues that have pending jobs will be returned
     * 
     * @param bool $hide_non_active
     * 
     * @return string[]
     */
    static function get_all_queue_names($hide_non_active=FALSE)
    {
        /** @var \WPDB $wpdb */
        global $wpdb;
    
        $statuses = [self::STATUS_ABANDONED, self::STATUS_DONE];
        $status_placeholders = array_fill(0, count($statuses), '%s');

        $query = $hide_non_active
            ? $wpdb->prepare(
                "SELECT `post_mime_type` FROM ``{$wpdb->posts}`` WHERE `post_status` NOT IN ({$status_placeholders})",
                $statuses
            )
            : $wpdb->prepare("SELECT `post_mime_type` FROM ``{$wpdb->posts}`");

        return array_map([self::class, 'from_post'], $wpdb->get_results($query));
    }

    /**
     * Gets the label for the job
     *
     * @return string
     */
    function get_label()
    {
        return $this->_label;
    }

    /**
     * Gets the dataset associated with the job
     *
     * @return mixed
     */
    function get_dataset()
    {
        return $this->_dataset;
    }

    /**
     * Sets the dataset for the job. Note, you'll need to save the job manually
     *
     * @param mixed $data
     * @return self
     */
    protected function set_dataset($data)
    {
        $this->_dataset = $data;
        return $this;
    }

    /**
     * Gets the status of the job
     *
     * @return string
     */
    function get_status()
    {
        return $this->_status;
    }

    /**
     * Gets the output of the job
     *
     * @return string
     */
    function get_output($join="\n")
    {
        return implode($join, $this->_output);
    }

    /**
     * Gets the history of the job
     *
     * @param string $join
     * @return string
     */
    function get_history($join = "\n")
    {
        return implode($join, $this->_history);
    }

    /**
     * Gets the name of the job queue
     *
     * @return string
     */
    function get_queue_name()
    {
        return $this->_queue;
    }

    /**
     * Gets the ID of the worker
     *
     * @return string
     */
    function get_worker_id()
    {
        return $this->_worker_id;
    }

    /**
     * Gets the time estimated for this job to complete
     *
     * @return number
     */
    function get_time_estimate()
    {
        return $this->_time_estimate;
    }

    /**
     * Gets the ID of the job
     *
     * @return int
     */
    function get_id()
    {
        return $this->_id;
    }

    /**
     * Gets the ID of the parent job
     *
     * @return int
     */
    function get_parent_id()
    {
        return $this->_parent_job_id;
    }

    /**
     * Gets the parent job
     *
     * @return self
     */
    function get_parent()
    {
        return self::from_post(get_post($this->get_parent_id()));
    }

    /**
     * Gets the number of retry attempts thus far
     *
     * @return int
     */
    function get_number_of_retry_attempts()
    {
        return $this->_retry_i;
    }

    /**
     * Determines whether the job can yet be retried
     *
     * @return bool
     */
    function can_be_retried()
    {
        return self::get_status() != self::STATUS_ABANDONED && ($this->_retry_i <= $this->_max_retries) && $this->_max_retries !== 0;
    }

    /**
     * Determines whether the job has been claimed by a worker or not
     *
     * @return bool
     */
    function is_claimed()
    {
        return isset($this->_worker_id);
    }

    /**
     * Marks a job as failed
     *
     * @param \Exception|null $ex
     * @return self
     */
    function mark_as_failed(\Exception $ex = NULL)
    {
        if ($ex)
        {
            $this->logHistory("A problem occured processing the job: {$ex->getMessage()}");
            $this->_exception = $ex;
        }

        $this->_retry_i += 1;

        if ($this->can_be_retried())
        {
            $this->_status = self::STATUS_FAILED;
            $this->logHistory("Job failed in attempt #{$this->_retry_i}");
        }
        else {
            $this->_status = self::STATUS_ABANDONED;
            $this->logHistory("Job abandoned after attempt #{$this->_retry_i}");
        }

        $this->save($this->get_queue_name());

        return $this;
    }

    /**
     * Marks a job as complete
     *
     * @return self
     */
    function mark_as_done()
    {
        $this->_status = self::STATUS_DONE;
        $this->logHistory("Job is complete");
        return $this->save($this->get_queue_name());
    }

    /**
     * Creates a new Job from a post
     *
     * @param WP_Post $post
     * @return Job
     */
    static function from_post(WP_Post $post)
    {
        $props = json_decode($post->post_content, TRUE);
        $klass = self::$_registered_types[$props['_type']];
        
        $props['_label']         = $post->post_title;
        $props['_id']            = $post->ID;
        $props['_worker_id']     = $post->post_password;
        $props['_queue']         = str_replace("queue/", "", $post->post_mime_type);
        $props['_status']        = $post->post_status;
        $props['_parent_job_id'] = $post->post_parent;

        return new $klass($props);
    }

    /**
     * Creates a Job from a post, specified by its ID
     *
     * @param int $post_id
     * @return Job
     */
    static function from_post_id($post_id)
    {
        if (($post = get_post($post_id)))
            return self::from_post($post);

        return self::from_post(WP_Post::get_instance($post_id));
    }

    /**
     * Returns the name of the class used to handle jobs of the particular type
     *
     * @param string type
     * @return string
     * @throws E_UnregisteredJobType
     */
    protected static function _get_type_class($type)
    {
        if (!isset(self::$_registered_types[$type]))
            throw new E_UnregisteredJobType("A type has not been registered for '{$type}'");

        return self::$_registered_types[$type];
    }

    /**
     * @param string $label
     * @param string $type
     * @param mixed $dataset
     * @param int $parent_job_id
     * @return Job
     */
    static function create($label, $type, $dataset = [], $parent_job_id = 0)
    {
        $klass = self::_get_type_class($type);

        return new $klass([
            '_label'         => $label,
            '_type'          => $type,
            '_dataset'       => $dataset,
            '_parent_job_id' => $parent_job_id]
        );
    }

    /**
     * Returns a WP_Post representation of the Job
     *
     * @param string $queue the name of the queue to be associated with this Job
     * @param string|null $worker_id
     * @return WP_Post
     */
    function to_post($queue, $worker_id = NULL)
    {
        // Update some props passed in
        $this->_queue = $queue;
        $this->_worker_id = $worker_id;

        // Construct array with custom fields
        $other_data = array_reduce(
            ['_undesirable_prop'],
            function($retval, $prop) {
                unset($retval[$prop]);
                return $retval;
            },
            get_object_vars($this)
        );

        // WP props
        $data = new stdClass;
        $data->ID             = $this->_id;
        $data->post_password  = $this->_worker_id ? $this->_worker_id: ''; // post_password is worker_id
        $data->post_type      = self::POST_TYPE;
        $data->post_status    = $this->_status;
        $data->post_title     = $this->_label;
        $data->post_content   = json_encode($other_data);
        $data->post_mime_type = "queue/{$queue}"; // post_mime_type is the queue
        $data->post_parent    = $this->_parent_job_id;

        // Apply overrides
        if ($worker_id)
            $data->post_password = $worker_id;

        return new WP_Post($data);
    }

    /**
     * Saves the job in the DB
     *
     * @param string $queue
     * @param string|null $worker_id
     * @return self
     */
    function save($queue, $worker_id = NULL)
    {
        $previously_unqueued = $this->get_status() === self::STATUS_UNQUEUED;

        // If the Job was previously enqueued, then we're now enqueuing it
        if ($previously_unqueued)
            $this->_status = self::STATUS_QUEUED;

        $this->logHistory("Job was persisted to the DB");
        $this->_id = $this->_id
            ? wp_update_post($this->to_post($queue, $worker_id), TRUE)
            : wp_insert_post($this->to_post($queue, $worker_id), TRUE);

        if (!is_wp_error($this->_id))
        {
            $this->_queue = $queue;
            $this->_worker_id = $worker_id;

            if ($this->_status == self::STATUS_QUEUED)
                do_action('reactr_bg_job_added', $this);
        }
        else {
            /** @var \WP_Error $err */
            $err = $this->_id;
            $this->_id = 0;
            if ($previously_unqueued)
                $this->_status = self::STATUS_UNQUEUED;
            throw new E_SaveJob($err->get_error_message());        
        }

        return $this;
    }

    /**
     * Deletes a job
     *
     * @return Job
     * @throws E_DequeueJob
     */
    function delete()
    {
        if (wp_delete_post($this->get_id()))
        {
            $this->_id        = 0;
            $this->_worker_id = '';
            $this->_queue     = '';
            return $this;
        }

        throw new E_DequeueJob("Could not dequeue {$this->get_id()}");
    }

    /**
     * Logs an entry to the job's history record
     *
     * @param string $msg
     * @param number $timestamp
     * @return self
     */
    function logHistory($msg, $timestamp = NULL)
    {
        $date = date("%r", $timestamp);
        $this->_history[] = "{$date}\t{$msg}";
        return $this;
    }

    /**
     * Logs output for the job
     *
     * @param string $msg
     * @param null|int $timestamp
     * @return self
     */
    function logOutput($msg, $timestamp = NULL)
    {
        $date = date("%r", $timestamp);
        $this->_output[] = "{$date}\t{$msg}";
        return $this;
    }

    /**
     * Removes the job from the worker's queue
     *
     * @return self
     */
    function unclaim()
    {
        $this->logHistory("Job was unclaimed from {$this->get_worker_id()}");
        $this->_worker_id = '';
        $this->save($this->get_queue_name());
        return $this;
    }
}
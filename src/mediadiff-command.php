<?php

// only run through WP CLI.
if (! defined('WP_CLI')) :
    exit(0);
endif;

/**
 * List and delete media items contaned in the uploads folder with no entry in the WordPress database.
 *
 * ## EXAMPLES
 *
 *     # List orphaned media
 *     $ wp mediadiff display --list=diff --format=table
 *     +---------+-----------------+--------------------------------+
 *     | Path    | File            | Sizes                          |
 *     +---------+-----------------+--------------------------------+
 *     | 2017/03 | filename.png    | 312x338 220x165 152x165        |
 *     +---------+-----------------+--------------------------------+
 *
 *     # Delete orphaned media
 *     $ wp mediadiff delete --hard
 *     Permanently delete orphaned media? [y/n] y
 *     Hard deleting 1 orphaned media.
 *     Progress  100% [===================================================] 0:00 / 0:00
 *     Progress  100% [===================================================] 0:01 / 0:01
 *     +-------------------------------------------------------------------+---------+
 *     | File                                                              | Deleted |
 *     +-------------------------------------------------------------------+---------+
 *     | /public/wp-content\uploads/2017/03/filename.png                   | 1       |
 *     | /public/wp-content\uploads/2017/03/filename-312x338.png           | 1       |
 *     | /public/wp-content\uploads/2017/03/filename-220x165.png           | 1       |
 *     | /public/wp-content\uploads/2017/03/filename-152x165.png           | 1       |
 *     +-------------------------------------------------------------------+---------+
 */
class Mediadiff_Display_Command extends \WP_CLI_Command
{
    /**
     * List orphaned media attachments with WP CLI.
     *
     * ### Options
     *
     * #### `[--format=table,json,csv,yaml,count]`
     * Set the output format.  Defaults to 'table'.
     *
     * #### `[--list=database,filesystem,diff]`
     * Set this to list all database media, all filesystem media or the orphaned media from the filesystem (diff).
     * Defaults to 'diff'.
     *
     * #### `[--upload-dir]`
     * The URL to the uploads directory, not including any date based folder structure. Used when [--list=filesystem,diff].
     * Defaults to current WordPress uploads directory.
     *
     * #### `[--orderby]`
     * Set the query order file when listing database media. Defaults to 'date'.
     *
     * #### `[--order]`
     * Set the query order when listing database media. Defaults to 'ASC'.
     *
     * ### Examples
     *
     *     wp mediadiff display --upload-dir=http://www.bedrock.com/app/uploads/
     *     wp mediadiff display --format=csv --list=filesystem
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function display(array $args = [], array $assoc_args = [])
    {
        $format = $this->get_flag_value($assoc_args, 'format', 'table', ['table', 'json', 'csv', 'yaml', 'count']);
        $list_type = $this->get_flag_value($assoc_args, 'list', 'diff', ['database', 'filesystem', 'diff']);

        switch ($list_type) {
            case 'database':
                $lines = $this->get_database_table($assoc_args);

                \WP_CLI\Utils\format_items($format, $lines, ['Id', 'Name', 'Path']);
                break;
            case 'filesystem':
                $lines = $this->get_filesystem_table($assoc_args);

                \WP_CLI\Utils\format_items($format, $lines, ['Path', 'File', 'Sizes']);
                break;
            case 'diff':
                $lines = $this->get_diff_table($assoc_args);

                \WP_CLI\Utils\format_items($format, $lines, ['Path', 'File', 'Sizes']);
                break;
        }
    }

    /**
     * Delete orphaned media attachments with WP CLI.
     *
     * ### Options
     *
     * #### `[--upload-dir]`
     * The URL to the uploads directory, not including any date based folder structure.
     * Defaults to current WordPress uploads directory.
     *
     * #### `[--hard]`
     * Leave flag unset to display a list of media that will be deleted without affecting the file system.
     *
     * ### Examples
     *
     *     wp mediadiff delete --upload-dir=http://www.bedrock.com/app/uploads/
     *     wp mediadiff delete --hard
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function delete(array $args = [], array $assoc_args = [])
    {
        $hard_delete = (bool) $this->get_flag_value($assoc_args, 'hard', false);
        $upload_dir = rtrim($this->get_flag_value($assoc_args, 'upload-dir', wp_upload_dir()['basedir']), '/');

        if ($hard_delete) {
            \WP_CLI::confirm('Permanently delete orphaned media?', $assoc_args);
        }
        if (! file_exists($upload_dir) || ! is_dir($upload_dir)) {
            \WP_CLI::error(sprintf('Invalid upload directory: %s', $upload_dir));
        }

        $orphaned_media = $this->get_diff_table($assoc_args);
        if (count($orphaned_media) < 1) {
            \WP_CLI::success('No orphaned media items to delete.');
        }


        // Output information about what the CLI command is doing.
        \WP_CLI::line(sprintf('%s deleting %d orphaned media.', $hard_delete ? 'Hard' : 'Soft', count($orphaned_media)));

        // Create a progress bar.
        $progress = new \cli\progress\Bar('Progress', count($orphaned_media));

        // loop through items
        $files_deleted = [];
        foreach ($orphaned_media as $orphan) {

            $progress->tick();

            // check directory exists
            $directory_path = $upload_dir . '/' . $orphan->Path;
            if (! file_exists($directory_path) || ! is_dir($directory_path)) {
                \WP_CLI::warning(sprintf('Invalid directory %s. Skipping file %s.', $directory_path, $orphan->File));

                continue;
            }

            if (! is_writable($directory_path)) {
                \WP_CLI::warning(sprintf('Directory %s is not writable. Skipping file %s.', $directory_path, $orphan->File));

                continue;
            }

            // create file list
            $files = [
                $directory_path . '/' . $orphan->File
            ];
            if ($orphan->Sizes) {
                $sizes = explode(' ', $orphan->Sizes);
                $file_name = pathinfo($orphan->File, PATHINFO_FILENAME);
                $file_extension = pathinfo($orphan->File, PATHINFO_EXTENSION);
                foreach ($sizes as $size) {
                    $files[] = sprintf('%s%s%s-%s.%s', $directory_path, '/', $file_name, $size, $file_extension);
                }
            }

            // delete files
            if (! $hard_delete) {
                foreach ($files as $file) {
                    $files_deleted[] = (object)[
                        'File' => $file,
                        'Deleted' => 1
                    ];
                }

                continue;
            }

            foreach ($files as $file) {
                if (! file_exists($file)) {
                    $files_deleted[] = (object)[
                        'File' => $file,
                        'Deleted' => 0
                    ];

                    \WP_CLI::warning(sprintf('Skipping invalid file %s.', $file));

                    continue;
                }

                $success = unlink($file);
                if (! $success) {
                    $files_deleted[] = (object)[
                        'File' => $file,
                        'Deleted' => 0
                    ];

                    \WP_CLI::warning(sprintf('Unable to delete file %s.', $file));

                    continue;
                } else {
                    $files_deleted[] = (object)[
                        'File' => $file,
                        'Deleted' => 1
                    ];
                }
            }
        }

        $progress->finish();

        \WP_CLI\Utils\format_items('table', $files_deleted, ['File', 'Deleted']);
    }

    /**
     * Get a list of files present in the file system
     * but not the WordPress posts table.
     *
     * @param array $assoc_args
     *
     * @return array
     */
    private function get_diff_table($assoc_args)
    {
        $database_items = $this->get_database_table($assoc_args);
        $database_keyed = [];
        foreach ($database_items as $database_item) {
            $database_keyed[$database_item->Path] = true;
        }

        $filesystem_items = $this->get_filesystem_table($assoc_args);
        $filesystem_no_db = [];
        foreach ($filesystem_items as $filesystem_item) {
            $filesystem_path = $filesystem_item->Path . '/' . $filesystem_item->File;
            if (! isset($database_keyed[$filesystem_path])) {
                // item not in db
                $filesystem_no_db[] = clone $filesystem_item;
            }
        }

        return $filesystem_no_db;
    }

    /**
     * Extract a value from the associative arguments.
     *
     * @param array $assoc_args CLI arguments
     * @param string $flag Argument key
     * @param mixed $default
     * @param array|null $allowed_values Array of permissible values
     *
     * @return mixed
     */
    private function get_flag_value($assoc_args, $flag, $default, $allowed_values = null)
    {
        $flag_value = \WP_CLI\Utils\get_flag_value($assoc_args, $flag, $default);
        if (is_array($allowed_values) && ! in_array($flag_value, $allowed_values)) {
            \WP_CLI::error(sprintf('Invalid flag value: %s', $flag_value));
        }

        return $flag_value;
    }

    /**
     * Get a list of files from the filesystem.
     *
     * @param array $assoc_args
     *
     * @return array
     */
    private function get_filesystem_table($assoc_args)
    {
        $upload_dir = rtrim($this->get_flag_value($assoc_args, 'upload-dir', wp_upload_dir()['basedir']), '/');
        if (! file_exists($upload_dir) || ! is_dir($upload_dir)) {
            \WP_CLI::error(sprintf('Invalid directory: %s', $upload_dir));
        }

        $lines = [];
        $directory_iterator = new DirectoryIterator($upload_dir);
        if (! $directory_iterator->isReadable()) {
            \WP_CLI::error('Upload directory is not readable');
        }

        $this->read_directory_to_array($directory_iterator, $lines);

        return $lines;
    }

    /**
     * Read directories of the format 20xx from upload root
     * and fill data into an array.
     *
     * @param DirectoryIterator $directory_iterator
     * @param array $lines
     */
    private function read_directory_years(DirectoryIterator $directory_iterator, &$lines)
    {
        foreach ($directory_iterator as $file_info) {
            if ($file_info->isDot()) {
                // skip . and ..
                continue;
            }

            if (! $file_info->isDir()) {
                continue;
            }

            if (! $file_info->isReadable()) {
                //unless they're not readable
                \WP_CLI::error(sprintf('Directory %s is not readable', $file_info->getRealPath()), false);

                continue;
            }

            $directory_name = $file_info->getBasename();
            if (! preg_match('/^20..$/', $directory_name)) {
                continue;
            }

            $this->read_directory_months(new DirectoryIterator($file_info->getRealPath()), $lines, $file_info->getBasename());
        }
    }

    /**
     * Read directories of the format [0-1][0-9] from a year directory
     * and fill data into an array.
     *
     * @param DirectoryIterator $directory_iterator
     * @param array $lines
     * @param string $root_path The parent year directory
     */
    private function read_directory_months(DirectoryIterator $directory_iterator, &$lines, $root_path)
    {
        foreach ($directory_iterator as $file_info) {
            if ($file_info->isDot()) {
                // skip . and ..
                continue;
            }

            if (! $file_info->isDir()) {
                continue;
            }

            if (! $file_info->isReadable()) {
                //unless they're not readable
                \WP_CLI::error(sprintf('Directory %s is not readable', $file_info->getRealPath()), false);

                continue;
            }

            $directory_name = $file_info->getBasename();
            if (! preg_match('/^[0-1][0-9]$/', $directory_name)) {
                continue;
            }

            $this->read_directory_no_month_year(new DirectoryIterator($file_info->getRealPath()), $lines, $root_path . '/' . $directory_name);
        }
    }

    /**
     * Read the files in a directory into an array
     * skipping directories and checking for resized equivalent versions
     * of the same file.
     *
     * @param DirectoryIterator $directory_iterator
     * @param array $lines
     * @param string $path
     */
    private function read_directory_no_month_year(DirectoryIterator $directory_iterator, &$lines, $path = '')
    {
        $resized_regex = '/^(?P<base_name>.*)-(?P<size>\d+x\d+)\.(?P<extension>[a-z0-9]+)$/';
        $directory_files = [];
        foreach ($directory_iterator as $file_info) {
            if ($file_info->isDot() || $file_info->isDir()) {
                // skip directories
                continue;
            }

            // check if this is a resized image
            $base_name = $file_info->getBasename();
            $matches = [];
            if (preg_match($resized_regex, $base_name, $matches)) {
                $root_file_base_name = $matches['base_name'] . '.' . $matches['extension'];
                $root_file = $file_info->getPathInfo()->getRealPath() . '/' . $root_file_base_name;
                if (file_exists($root_file)) {
                    // this file is a resized image
                    $directory_files[$root_file_base_name]['Sizes'][] = $matches['size'];

                    continue;
                }
            }

            // add file to list
            $directory_files[$base_name]['Path'] = $path;
            if (! array_key_exists('Sizes', $directory_files[$base_name])) {
                $directory_files[$base_name]['Sizes'] = [];
            }
        }

        foreach ($directory_files as $base_name => $file_data) {
            $sizes = '';
            if (array_key_exists('Sizes', $file_data)) {
                rsort($file_data['Sizes'], SORT_NATURAL);

                $sizes = implode(' ', $file_data['Sizes']);
            }

            $lines[] = (object)[
                'Path' => $file_data['Path'],
                'File' => $base_name,
                'Sizes' => $sizes,
            ];
        }
    }

    /**
     * Read the files in a directory into an array
     * skipping directories and checking for resized equivalent versions
     * of the same file.
     * Checks the 'uploads_use_yearmonth_folders' to handle possible WordPress
     * directory structures.
     *
     * @param DirectoryIterator $directory_iterator
     * @param array $lines
     */
    private function read_directory_to_array(DirectoryIterator $directory_iterator, &$lines)
    {
        $year_month_folders = get_option('uploads_use_yearmonth_folders');
        if ($year_month_folders) {
            $this->read_directory_years($directory_iterator, $lines);
        } else {
            $this->read_directory_no_month_year($directory_iterator, $lines);
        }
    }

    /**
     * Get a list of files from the database.
     *
     * @param array $assoc_args
     *
     * @return array
     */
    private function get_database_table($assoc_args)
    {
        $orderby = $this->get_flag_value($assoc_args, 'orderby', 'date', ['name', 'date', 'ID']);
        $order = $this->get_flag_value($assoc_args, 'order', 'ASC', ['ASC', 'DESC']);

        // get attachments
        $attachments = $this->get_attachments($orderby, $order);
        $lines = [];
        foreach ($attachments as $attachment_id) {
            $attachment = get_post($attachment_id);
            $file_path = get_post_meta($attachment_id, '_wp_attached_file', true);

            $lines[] = (object)[
                'Id' => $attachment->ID,
                'Name' => $attachment->post_name,
                'Path' => $file_path
            ];
        }

        return $lines;
    }

    /**
     * Get all attachment IDs from the database.
     *
     * @param string $orderby
     * @param string $order
     *
     * @return array
     */
    private function get_attachments($orderby = 'date', $order = 'DESC')
    {
        return (
            new \WP_Query(
                [
                    'post_type' => 'attachment',
                    'post_status' => 'any',
                    'nopaging' => true,
                    'fields' => 'ids',
                    'order' => $order,
                    'orderby' => $orderby
                ]
            )
        )
            ->get_posts();
    }
}

\WP_CLI::add_command('mediadiff', 'Mediadiff_Display_Command');

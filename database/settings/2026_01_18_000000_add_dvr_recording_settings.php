<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // DVR/Recording functionality
        if (! $this->migrator->exists('general.recording_enabled')) {
            $this->migrator->add('general.recording_enabled', false);
        }

        // Recording file location
        if (! $this->migrator->exists('general.recording_file_location')) {
            $this->migrator->add('general.recording_file_location', null);
        }

        // Recording path structure
        if (! $this->migrator->exists('general.recording_file_path_structure')) {
            $this->migrator->add('general.recording_file_path_structure', null);
        }

        // Recording filename metadata
        if (! $this->migrator->exists('general.recording_filename_metadata')) {
            $this->migrator->add('general.recording_filename_metadata', null);
        }

        // Recording filename cleansing options
        if (! $this->migrator->exists('general.recording_clean_special_chars')) {
            $this->migrator->add('general.recording_clean_special_chars', true);
        }
        if (! $this->migrator->exists('general.recording_remove_consecutive_chars')) {
            $this->migrator->add('general.recording_remove_consecutive_chars', true);
        }
        if (! $this->migrator->exists('general.recording_replace_char')) {
            $this->migrator->add('general.recording_replace_char', 'space');
        }

        // Recording name filtering options
        if (! $this->migrator->exists('general.recording_name_filter_enabled')) {
            $this->migrator->add('general.recording_name_filter_enabled', false);
        }
        if (! $this->migrator->exists('general.recording_name_filter_patterns')) {
            $this->migrator->add('general.recording_name_filter_patterns', null);
        }
    }
};

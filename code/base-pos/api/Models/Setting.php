<?php
class Setting extends Model
{
    /**
     * @var string
     */
    protected $table = 'settings';

    /**
     * @param array $keys
     */
    public function getSettingsByKeys($keys = [])
    {
        $result = [];

        if (empty($keys)) {
            // Get all settings
            $settings = $this->db->fetchAll("SELECT setting_key, setting_value FROM {$this->table}");

            foreach ($settings as $setting) {
                $result[$setting['setting_key']] = $setting['setting_value'];
            }
        } else {
            // Get specific settings
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $settings = $this->db->fetchAll(
                "SELECT setting_key, setting_value FROM {$this->table} WHERE setting_key IN ($placeholders)",
                $keys
            );

            // Fill in missing keys with null
            foreach ($keys as $key) {
                $result[$key] = null;
            }

            // Populate with actual values
            foreach ($settings as $setting) {
                $result[$setting['setting_key']] = $setting['setting_value'];
            }
        }

        return $result;
    }

    /**
     * @param $settings
     */
    public function updateSettings($settings)
    {
        $this->db->beginTransaction();

        try {
            foreach ($settings as $key => $value) {
                if ($value === null) {
                    continue;
                }

                // Check if setting already exists
                $exists = $this->db->fetchColumn(
                    "SELECT COUNT(*) FROM {$this->table} WHERE setting_key = ?",
                    [$key]
                );

                if ($exists > 0) {
                    // Update existing setting
                    $this->db->update(
                        $this->table,
                        ['setting_value' => $value],
                        ['setting_key = ?'],
                        [$key]
                    );
                } else {
                    // Insert new setting
                    $this->db->insert($this->table, [
                        'setting_key' => $key,
                        'setting_value' => $value
                    ]);
                }
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}

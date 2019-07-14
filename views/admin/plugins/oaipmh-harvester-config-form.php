<fieldset id="fieldset-oai-pmh-harvester-rights"><legend><?php echo __('Rights and Roles'); ?></legend>
    <div class="field">
        <div class="two columns alpha">
            <?php echo $this->formLabel('oaipmh_harvester_allow_roles', __('Roles that can use OAI-PMH Harvester')); ?>
        </div>
        <div class="inputs five columns omega">
            <div class="input-block">
                <?php
                    $currentRoles = unserialize(get_option('oaipmh_harvester_allow_roles')) ?: array();
                    $userRoles = get_user_roles();
                    echo '<ul>';
                    foreach ($userRoles as $role => $label) {
                        echo '<li>';
                        echo $this->formCheckbox(
                            'oaipmh_harvester_allow_roles[]',
                            $role,
                            array('checked' => in_array($role, $currentRoles) ? 'checked' : '')
                        );
                        echo $label;
                        echo '</li>';
                    }
                    echo '</ul>';
                ?>
            </div>
        </div>
    </div>
    <div class="field">
        <div class="two columns alpha">
            <?php echo $this->formLabel('oaipmhharvester_http_client_timeout', __('Timeout')); ?>
        </div>
        <div class="inputs five columns omega">
            <p class="explanation">
                <?php echo __("Timeout of the HTTP client, in seconds. Defaults to 10."); ?>
            </p>
            <?php echo $this->formText('oaipmhharvester_http_client_timeout', get_option('oaipmhharvester_http_client_timeout')); ?>
        </div>
    </div>
</fieldset>

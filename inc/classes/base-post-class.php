<?php
class FRC_Post_Base_Class {
    public      $acf_fields;
    public      $categories;
    public      $components;

    public      $included_acf_fields;

    public      $cache_options = [
                    'cache_whole_object'    => true,
                    'cache_acf_fields'      => true,
                    'cache_categories'      => true,
                    'cache_component_list'  => true,
                    'cache_components'      => true
                ];

    protected   $keep_build_data    = false;
    public      $served_from_cache  = false;
    private     $post_constructed   = false;

    public function __construct ($post_id = null, $cache_options = []) {
        $this->definition();
        
        if($post_id) {
            $this->cache_options = array_replace_recursive($this->cache_options, $cache_options);

            $this->prepare_component_list($post_id);

            $this->remove_unused_post_data();
            
            //Construct the real post object
            $this->construct_post_object($post_id);

            $this->init();
        }
    }

    public function remove_unused_post_data () {
        if(!$this->keep_build_data) {
            unset($this->taxonomies);
            unset($this->acf_schema);
            unset($this->acf_schema_groups);
            unset($this->args);
        }
    }

    private function prepare_component_list ($post_id) {
        $transient_key = '_frc_api_post_component_list_' . $post_id;

        if(!$this->cache_options['cache_component_list'] || ($component_list = get_transient($transient_key)) === false) {
            $component_list = [];
            foreach($this->acf_schema as $schema) {
                if($schema['type'] == 'frc_components') {
                    foreach(frc_api_get_components_of_type($schema['frc_component_type']) as $component) {
                        $reference_class = new $component();

                        $component_list[$schema['name']][$reference_class->get_key_name()] = $component;
                    }
                }
            }

            set_transient($transient_key, $component_list);
            frc_api_add_transient_to_group_list("post_" . $post_id, $transient_key);
        }

        $this->components = $component_list;
    }

    private function construct_post_object ($post_id) {
        $this->post_constructed = true;

        $transient_key = '_frc_api_post_object_' . $post_id;

        if(!$this->cache_options['cache_whole_object'] || ($transient_data = get_transient($transient_key)) === false) {
            $post = get_object_vars(WP_Post::get_instance($post_id));

            $this->construct_acf_fields($post_id);
            
            $this->construct_categories($post_id);

            $transient_data = ['post' => $post, 'acf_fields' => $this->acf_fields, "categories" => $this->categories];

            if($this->cache_options['cache_whole_object']) {
                frc_api_add_transient_to_group_list("post_" . $post_id, $transient_key);
                set_transient($transient_key, $transient_data);
            }
        } else {
            $this->served_from_cache = true;
        }

        foreach($transient_data['post'] as $post_key => $post_value) {
            $this->$post_key = $post_value;
        }

        $this->acf_fields       = $transient_data['acf_fields'];
        $this->categories       = $transient_data['categories'];
    }

    public function construct_acf_fields ($post_id) {
        if($this->cache_options['cache_acf_fields']) {
            $transient_key = '_frc_api_post_acf_field_' . $post_id;
            if(($this->acf_fields = get_transient($transient_key)) === false && function_exists('get_fields')) {
                $this->acf_fields = get_fields($post_id);

                //If included acf fields is set, only include those acf fields
                if(!empty($this->included_acf_fields)) {
                    foreach($this->acf_fields as $acf_key => $acf_value) {
                        if(!in_array($acf_key, $this->included_acf_fields)) {
                            unset($this->acf_fields[$acf_key]);
                        }
                    }
                }

                frc_api_add_transient_to_group_list("post_" . $post_id, $transient_key);
                set_transient($transient_key, $this->acf_fields);
            }
        } else {
            $this->acf_fields = get_fields($post_id);
        }
    }

    public function construct_categories ($post_id) {
        $transient_key = '_frc_api_post_categories_' . $post_id;
        if(($this->categories = get_transient($transient_key)) === false) {
            foreach(get_categories($post_id) as $category) {
                $this->categories[] = get_object_vars($category);
            }

            if($this->cache_options['cache_categories']) {
                frc_api_add_transient_to_group_list("post_" . $post_id, $transient_key);
                set_transient($transient_key, $this->categories);
            }
        }
    }

    public function get_components () {
        $transient_key = '_frc_api_post_components_' . $this->ID;

        if(!$this->cache_options['cache_components'] || ($components = get_transient($transient_key)) === false) {
            $components = [];

            foreach($this->acf_fields as $key => $field) {
                if(!isset($this->components[$key]))
                    continue;
    
                foreach($this->acf_fields[$key] as $component_key => $component_field) {
                    $component_class = $this->components[$key][$component_field['acf_fc_layout']];
                    
                    $new_component = new $component_class();
                    $new_component->prepare($component_field);
                    $components[] = $new_component;
                }
            }

            set_transient($transient_key, $components);
            frc_api_add_transient_to_group_list("post_" . $this->ID, $transient_key);
        }

        return $components;
    }

    public function save () {
        if(!$this->post_constructed)
            return false;

        wp_update_post($this);

        foreach($this->acf_fields as $field_key => $field_value) {
            update_field($field_key, $field_value, $this->ID);
        }

        frc_api_delete_transients_in_group("post_" . $this->ID);

        $this->saved();

        return true;
    }

    public function get_key_name () {
        return frc_api_name_to_key(get_class($this));
    }

    protected function init () {
    }

    protected function definition () {
    }

    protected function saved () {
    }
}

/*
    Create this just so that we can wrap regular wp_post's
    through the system and as this is not a custom post type,
    there is no need to put that through the registering machine.
*/
class FRC_Post extends FRC_Post_Base_Class {
}
frc_exclude_class("FRC_Post", "FRC_Post_Base_Class");

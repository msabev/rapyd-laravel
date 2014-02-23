<?php

namespace Zofe\Rapyd\DataForm;

use Illuminate\Database\Eloquent\Model;
use Zofe\Rapyd\DataForm\Field\File;
use Zofe\Rapyd\DataForm\Field\Redactor;
use Zofe\Rapyd\DataForm\Field\Select;
use Zofe\Rapyd\DataForm\Field\Submit;
use Zofe\Rapyd\DataForm\Field\Text;
use Zofe\Rapyd\DataForm\Field\Textarea;
use Zofe\Rapyd\Widget;
use Illuminate\Support\Facades\Form;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Redirect;

class DataForm extends Widget
{

    public $model;
    public $output = "";
    public $fields = array();
    public $hash = "";

    protected $redirect = null;
    protected $source;
    protected $process_url = '';
    protected $view = 'rapyd::dataform';

    public function __construct()
    {
        parent::__construct();
        $this->process_url = $this->url->append('process', 1)->get();
    }

    /**
     * @param string $name
     * @param string $label
     * @param string $type
     * @param string $validation
     *
     * @return mixed
     */
    public function add($name, $label, $type, $validation = '')
    {

        if (strpos($type, "\\")) {
            $field_class = $type;
        } else {
            $field_class = '\Zofe\Rapyd\DataForm\Field' . "\\" . ucfirst($type);
        }

        $field_obj = new $field_class($name, $label);
        if ($field_obj->type == "file") {
            $this->multipart = true;
        }

        //share model
        if (isset($this->model)) {
            $field_obj->model = & $this->model;
        }

        //default group
        if (isset($this->default_group) && !isset($field_obj->group)) {
            $field_obj->group = $this->default_group;
        }
        $this->fields[$name] = $field_obj;
        return $field_obj;
    }

    /**
     * @param string $name
     * @param string $position
     * @param array  $options
     *
     * @return $this
     */
    function submit($name, $position = "BL", $options = array())
    {
        $options = array_merge(array("class" => "btn btn-primary"), $options);
        $this->button_container[$position][] = Form::submit($name, $options);
        return $this;
    }

    /**
     * @param string $name
     * @param string $position
     * @param array  $options
     *
     * @return $this
     */
    function reset($name, $position = "BL", $options = array())
    {
        $options = array_merge(array("class" => "btn btn-default"), $options);
        $this->button_container[$position][] = Form::reset($name, $options);
        return $this;
    }

    /* public function submit($label)
      {
      $field_obj = $this->add('submit', $label, "submit");
      return $field_obj;
      } */

    public function &field($field_name)
    {
        if (isset($this->fields[$field_name])) {
            return $this->fields[$field_name];
        }
    }

    /**
     * @return static
     */
    public static function create()
    {
        $ins = new static;
        $ins->cid = $ins->getIdentifier();
        return $ins;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $source
     *
     * @return static
     */
    public static function source($source = '')
    {
        $ins = new static;
        if (is_object($source) && is_a($source, "\Illuminate\Database\Eloquent\Model")) {
            $ins->model = $source;
        }
        $ins->cid = $ins->getIdentifier();
        return $ins;
    }

    /**
     * @return bool
     */
    protected function isValid()
    {

        //some fields mode can disable or change some rules.
        foreach ($this->fields as $field) {
            $field->action = $this->action;
            //$field->get_mode();
            if (isset($field->rule)) {
                //if (($field->type != "upload") && $field->apply_rules) {
                //	$fieldnames[$field->name] = $field->label;
                $rules[$field->name] = $field->rule;
                //} else {
                //	$field->required = false;
                //}
            }
        }
        if (isset($rules)) {

            $this->validator = Validator::make(Input::all(), $rules);
            return !$this->validator->fails();
        } else {
            return true;
        }
    }

    /**
     * @param string $process_status
     *
     * @return bool
     */
    public function on($process_status = "false")
    {
        if (is_array($process_status))
            return (bool) in_array($this->process_status, $process_status);
        return ($this->process_status == $process_status);
    }

    protected function sniffStatus()
    {
        if (isset($this->model)) {
            $this->status = ($this->model->exists) ? "modify" : "create";
        } else {
            $this->status = "create";
        }
    }

    protected function buildFields()
    {
        foreach ($this->fields as $field) {
            //share status
            $field->status = $this->status;
            $field->build();
        }
    }

    protected function buildButtons()
    {
        
    }

    protected function sniffAction()
    {
        if (isset($_POST) && ( $this->url->value('process'))) {
            $this->action = ($this->status == "modify") ? "update" : "insert";
        }
    }

    protected function process()
    {
        //database save
        switch ($this->action) {
            case "update":
            case "insert":
                //validation failed
                if (!$this->isValid()) {
                    $this->process_status = "error";
                    foreach ($this->fields as $field) {
                        $field->action = "idle";
                    }
                    return false;
                } else {
                    $this->process_status = "success";
                }
                foreach ($this->fields as $field) {
                    $field->action = $this->action;
                    $result = $field->autoUpdate();
                    if (!$result) {
                        $this->process_status = "error";
                        return false;
                    }
                }
                if (isset($this->model)) {
                    $return = $this->model->save();
                } else {
                    $return = true;
                }
                if (!$return) {
                    $this->process_status = "error";
                }
                return $return;
                break;
            case "delete":
                $return = $this->model->delete();
                if (!$return) {
                    $this->process_status = "error";
                } else {
                    $this->process_status = "success";
                }
                break;
            case "idle":
                $this->process_status = "show";
                return true;
                break;
            default:
                return false;
        }
    }

    protected function buildForm()
    {
        $data = get_object_vars($this);
        $data['buttons'] = $this->button_container;
        
        $form_attr = array('url' => $this->process_url, 'class' => "form-horizontal", 'role' => "form");
        // See if we need a multipart form
        foreach ($this->fields as $field_obj) {
            if ($field_obj->type == 'file') {
                $form_attr['files'] = 'true';
                break;
            }
        }
        // Set the form open and close
        if ($this->status == 'show') {
            $data['form_begin'] = '<div class="form">';
            $data['form_end'] = '</div>';
        } else {


            $data['form_begin'] = Form::open($form_attr);
            $data['form_end'] = Form::close();
        }
        if (isset($this->validator)) {
            $data['errors'] = $this->validator->messages();
        }
        //var_dump($this->validator->messages()->all());
        $data['groups'] = $this->regroupFields($this->orderFields($this->fields));
        //$data['extra_class'] = $this->extra_class;
        return View::make($this->view, $data);
    }


    /**
     * @param string $view
     */
    public function build($view = '')
    {
        if ($this->output != '') return;
        if ($view!='') $this->view = $view;
        $this->sniffStatus();
        $this->sniffAction();
        $this->process();
        
        $this->buildFields();
        $this->buildButtons();
        $this->output = $this->buildForm();
    }

    /**
     * @param string $view
     *
     * @return string
     */
    public function getForm($view = '')
    {
        $this->build($view);
        return $this->output;
    }

    /**
     * @return bool
     */
    public function hasRedirect() {
        return ($this->redirect != null) ? true : false;
    }

    /**
     * @return string
     */
    public function getRedirect() {
        return $this->redirect;
    }

    /**
     * @param       $viewname
     * @param array $array
     *
     * @return View|Redirect
     */
    public function view($viewname, $array=array())
    {
        $form = $this->getForm();

        $array['form'] = $form;
        if ($this->hasRedirect()) return Redirect::to($this->getRedirect());
        return  View::make($viewname, $array);
    }

    /**
     * @param $fields
     *
     * @return array
     */
    protected function orderFields($fields)
    {
        //prepare nested fields
        foreach ($fields as $field_name => $field_ref) {
            if (isset($field_ref->in)) {
                if (isset($series_of_fields[$field_ref->in][0]) && $field_ref->label != "")
                    $series_of_fields[$field_ref->in][0]->label .= '/' . $field_ref->label;
                $series_of_fields[$field_ref->in][] = $field_ref;
            }
            else {
                $series_of_fields[$field_name][] = $field_ref;
            }
        }

        //prepare grouped fields
        $ordered_fields = array();
        foreach ($fields as $field_name => $field_ref) {
            if (!isset($field_ref->in)) {
                if (isset($field_ref->group)) {
                    $ordered_fields[$field_ref->group][$field_name] = $series_of_fields[$field_name];
                } else {
                    $ordered_fields["ungrouped"][$field_name] = $series_of_fields[$field_name];
                }
            }
        }
        return $ordered_fields;
    }

    protected function regroupFields($ordered_fields)
    {
        //build main array
        $groups = array();
        foreach ($ordered_fields as $group => $series_of_fields) {
            unset($gr);

            $gr["group_name"] = $group;

            foreach ($series_of_fields as $series_name => $serie_fields) {
                unset($sr);
                $sr["is_hidden"] = false;
                $sr["series_name"] = $series_name;

                foreach ($serie_fields as $field_ref) {
                    unset($fld);
                    if (($field_ref->status == "hidden" || $field_ref->visible === false || in_array($field_ref->type, array("hidden", "auto")))) {
                        $sr["is_hidden"] = true;
                    }

                    $fld["label"] = $field_ref->label;
                    $fld["id"] = $field_ref->name;
                    $fld["field"] = $field_ref->output;
                    $fld["type"] = $field_ref->type;
                    $fld["star"] = $field_ref->star;
                    $sr["fields"][] = $fld;
                }
                $gr["series"][] = $sr;
            }
            $groups[] = $gr;
        }

        return $groups;
    }


    /**
     * @param string $name
     * @param string $label
     * @param string $validation
     *
     * @return File
     */
    public function addFile($name, $label, $validation = '')
    {
        return $this->add($name , $label, 'file', $validation);
    }

    /**
     * @param string $name
     * @param string $label
     * @param string $validation
     *
     * @return Redactor
     */
    public function addRedactor($name, $label, $validation = '')
    {
        return $this->add($name , $label, 'redactor', $validation);
    }

    /**
     * @param string $name
     * @param string $label
     * @param string $validation
     *
     * @return Select
     */
    public function addSelect($name, $label, $validation = '')
    {
        return $this->add($name , $label, 'select', $validation);
    }

    /**
     * @param string $name
     * @param string $label
     * @param string $validation
     *
     * @return Submit
     */
    public function addSubmit($name, $label, $validation = '')
    {
        return $this->add($name , $label, 'submit', $validation);
    }

    /**
     * @param string $name
     * @param string $label
     * @param string $validation
     *
     * @return Text
     */
    public function addText($name, $label, $validation = '')
    {
        return $this->add($name , $label, 'text', $validation);
    }

    /**
     * @param string $name
     * @param string $label
     * @param string $validation
     *
     * @return Textarea
     */
    public function addTextarea($name, $label, $validation = '')
    {
        return $this->add($name , $label, 'textarea', $validation);
    }
}

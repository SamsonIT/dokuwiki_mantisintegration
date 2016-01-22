<?php
// must be run within DokuWiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once DOKU_PLUGIN.'syntax.php';

class syntax_plugin_mantisreporter extends DokuWiki_Syntax_Plugin 
{
    /**
     * @var int project id
     */
    private $projectId;
    
    /**
     * Soap client if initialized. Else null.
     *
     * @var mixed Soap client
     */
    private $soapClient = null;
    
    /**
     * get plugin type
     *
     * @return string 
     */
    public function getType() {
        return 'substition';
    }
    
    /**
     * get sorting order
     *
     * @return int 
     */
    public function getSort() {
        return 32;
    }
    
    /**
     * Define matching strings
     *
     * @param string $mode 
     */
    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('\{\{ mantisReport\?mantisProjectId=\d* }}', $mode, 'plugin_mantisreporter');
    }
    
    /**
     * set the parameters for $data for the render function
     *
     * @param string $match gematchte string
     * @param int $state
     * @param int $pos
     * @param DokuHandler $handler
     * 
     * @return array
     */
    public function handle($match, $state, $pos, Doku_Handler $handler) {
        return array($match, $state, $pos);
    }
    
    /**
     * Render the report html
     *
     * @param string $format
     * @param Doku_Renderer_metadata $renderer
     * @param array $data 
     * 
     * @return type boolean is de operatie geslaagd?
     */
    public function render($format, Doku_Renderer $renderer, $data) {
        
        if('xhtml' === $format) {
            try {
                $renderer->doc .= $this->saveIssue();
            } catch (Exception $e) {
                print 'Er ging iets fout: ' . $e->getMessage();
                unset($_POST['reporter']);
            }
            try {
                $this->parseData($data[0]);
                $renderer->doc .= $this->getHtml();
                return true;
            } catch (Exception $e) {
                print 'Er ging iets fout: ' . $e->getMessage();
                return false;
            }
        } else {
            return false;
        }
    }
    
    /**
     * Parse data to filter out variables
     *
     * @param string $data
     * 
     * @return boolean success 
     */
    private function parseData($data) {
        $regexId = '#mantisProjectId=(\d*)#i';
        $resultId = array();
        preg_match($regexId, $data, $resultId);
        $this->setProjectId($resultId[1]);
        return true;
    }
    
    /**
     * Get the project id
     *
     * @return int 
     */
    private function getProjectId() {
        return $this->projectId;
    }

    /**
     * Set the project id
     *
     * @param int $projectId 
     */
    private function setProjectId($projectId) {
        $this->projectId = $projectId;
    }

    /**
     * Get the html for priorities
     *
     * @return string 
     */
    private function getPrioritiesHtml() {
        $html = "<select name='priority'>";
        $priorities = $this->getPriorities();

        if(0 === count($priorities)) {
            return 'Geen prioriteiten gevonden';
        }
        
        foreach($priorities as $priority) {
            $html .= '<option ';
            if($priority->id == 30) {
                $html .= 'selected ';
            }
            $html .= 'value="'.$priority->id.'">'.$priority->name.'</option>';
        }
        return $html . '</select>';
    }
    
    /**
     * Get the html for reporters
     *
     * @return string 
     */
    private function getReportersHtml() {
        $caller = $_GET['caller'];
        $html = "<select name='reporter'>";
        $reporters = $this->getReporters();
        if(0 === count($reporters)) {
            return 'Geen reporters gevonden';
        }
        foreach($reporters as $reporter) {
            $html .= '<option value="'.$reporter->id . '"';
            
            if($reporter->name === $caller) {
                $html .= ' selected=selected ';
            }
            $html .= '>'.$reporter->real_name.'</option>';
        }
        return $html . '</select>';
    }

    /**
     * Get the html for categories
     *
     * @return string 
     */
    private function getCategoriesHtml() {
        $html = "<select name='category'>";
        $categories = $this->getCategories();
        if(0 === count($categories)) {
            return 'Geen categorie&euml;n gevonden';
        }
        foreach($categories as $category) {
            $html .= '<option value="'.$category.'">'.$category.'</option>';
        }
        return $html . '</select>';
    }
    
    /**
     * Get the html
     *
     * @return string 
     */
    private function getHtml() {
        $priorities = $this->getPrioritiesHtml();
        $reporters = $this->getReportersHtml();
        $categories = $this->getCategoriesHtml();
        $prognose = $this->getPrognoseHtml();
        $projectId = $this->getProjectId();
        $str = <<<EOD
<form method='POST'>
<input type='hidden' name='projectId' value='$projectId' />
    <table class='mantisreporter'>
        <tr>
            <td>reporter</td>
            <td>categorie</td>
            <td>prioriteit</td>
        </tr>
        <tr>
            <td>
                $reporters
            </td>
            <td>
                $categories
            </td>
            <td>
                $priorities
            </td>
        </tr>
        <tr>
            <td colspan='5'>samenvatting</td>
        </tr>
        <tr>
            <td colspan='5'>
                <input type='text' name='summary' class='summary' required />
            </td>
        </tr>
        <tr>
            <td colspan='5'>omschrijving</td>
        </tr>
        <tr>
            <td colspan='5'>
                <textarea name='description' required></textarea>
            </td>
        </tr>
        <tr>
            <td colspan='5' class='submit'>
                $prognose &nbsp;&nbsp;&nbsp; <input type='submit' value='ticket aanmaken' />
            </td>
        </tr>
    </table>
</form>
EOD;
        return $str;
    }
    
    /**
     * Get all priorities
     *
     * @return string 
     */
    private function getPriorities() {
        return $this->callSoapClient('mc_enum_priorities');
    }
    
    /**
     * Get all possible reporters for this project
     * access 25 means reporters and higher
     *
     * @return string 
     */
    private function getReporters() {
        $reporters = $this->callSoapClient('mc_project_get_users', array('project_id' => (int) $this->getProjectId(), 'access' => 25));
        foreach($reporters as $key => $reporter) {
            if($reporter->name === 'SoapUser') {
                unset($reporters[$key]);
            }
        }
        return $reporters;
    }
    
    /**
     * Get all possible reporters for this project
     *
     * @return string 
     */
    private function getCategories() {
        return $this->callSoapClient('mc_project_get_categories', array('project_id' => (int) $this->getProjectId()));
    }

    /**
     * Get all possible custom fields for this project
     *
     * @return string 
     */
    private function getCustomFields($projectId = null) {
        if($projectId === null) {
            $projectId = $this->getProjectId();
        } 
        return $this->callSoapClient('mc_project_get_custom_fields', array('project_id' => (int) $projectId));
    }
    
    /**
     * Get html for prognose (if available for this project).
     *
     * @return string 
     */
    private function getPrognoseHtml() {
        foreach($this->getCustomFields() as $customField) {
            if('prognose' === $customField->field->name) {
                return 'prognose <input type="text" min="0" name="prognose" class="prognose-input" required /> uur';
            }
        }
        return '';
    }
    
    private function getPrognoseId($projectId) {
        foreach($this->getCustomFields($projectId) as $customField) {
            if('prognose' === $customField->field->name) {
                return $customField->field->id;
            }
        }
        return '';
    }

    /**
     * Get the soap client
     *
     * @return SoapClient 
     */
    private function getSoapClient() {
        if($this->soapClient === null) {
            ini_set('default_socket_timeout', 5);
            $this->soapClient = new SoapClient($this->getMantisUrl() . '/api/soap/mantisconnect.php?wsdl', array("connection_timeout"=>5));
        }

        return $this->soapClient;
    }
    
    /**
     * Send a request to the soap client
     *
     * @param string $function what function should be called?
     * @param array $array extra parameters
     * 
     * @return mixed result from the request. 
     */
    private function callSoapClient($function, $array = array()) {
        $sc = $this->getSoapClient();
        $parameters = array('username' => $this->getConf('soap_user'), 'password' => $this->getConf('soap_password'));
        foreach($array as $key => $value) {
            $parameters[$key] = $value;
        }
        $returnValue = $sc->__call($function, $parameters, array());
        return $returnValue;
    }
    
    /**
     * Save issue
     *
     * @return string HTML with a link to the issue.
     */
    private function saveIssue() {
        if(isset($_POST['reporter'])) {
            $newIssue = new stdClass();
            
            $newIssue->reporter = new stdClass();
            $newIssue->reporter->id = (int) $_POST['reporter'];

            $newIssue->priority = new stdClass();
            $newIssue->priority->id = (int) $_POST['priority'];

            $newIssue->project = new stdClass();
            $newIssue->project->id = (int) $_POST['projectId'];

            $newIssue->category = $_POST['category'];
            $newIssue->summary = $_POST['summary'];
            $newIssue->description = $_POST['description'];
            
            if($_POST['prognose']) {
                $prognose = new stdClass();
                $prognose->field = new stdClass();
                $prognose->field->id = $this->getPrognoseId($_POST['projectId']);
                $prognose->field->name = 'prognose';
                
                $prognose->value = (float) $_POST['prognose'];
                $newIssue->custom_fields = array(
                        $prognose
                    );
            }
            $newIssueId = $this->callSoapClient('mc_issue_add', array('issue' => $newIssue));
            unset($_POST['reporter']);
            return 'Nieuw issue aangemaakt: <a href="'.$this->getMantisUrl().'/view.php?id=' . $newIssueId . '">' . $newIssueId . '</a><br><br>';
        }
    }
    
    /**
     * Get the mantis Url
     *
     * @return string 
     */
    private function getMantisUrl() {
        return $this->getConf('soap_url');
    }

    
    
    
    
    
 // configuration methods
  /**
   * getConf($setting)
   *
   * use this function to access plugin configuration variables
   */
  function getConf($setting){

    if (!$this->configloaded){ $this->loadConfig(); }

    return $this->conf[$setting];
  }

  /**
   * loadConfig()
   * merges the plugin's default settings with any local settings
   * this function is automatically called through getConf()
   */
  function loadConfig(){
    global $conf;

    $defaults = $this->readDefaultSettings();
    $plugin = $this->getPluginName();

    foreach ($defaults as $key => $value) {
      if (isset($conf['plugin'][$plugin][$key])) continue;
      $conf['plugin'][$plugin][$key] = $value;
    }

    $this->configloaded = true;
    $this->conf =& $conf['plugin'][$plugin];
  }

  /**
   * read the plugin's default configuration settings from conf/default.php
   * this function is automatically called through getConf()
   *
   * @return    array    setting => value
   */
  function readDefaultSettings() {

    $path = DOKU_PLUGIN.$this->getPluginName().'/conf/';
    $conf = array();

    if (@file_exists($path.'default.php')) {
      include($path.'default.php');
    }

    return $conf;
  }    

}
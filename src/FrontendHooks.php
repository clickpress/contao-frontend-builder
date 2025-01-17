<?php
/**
* Copyright (c) 2019 leycom - media solutions
* Copyright (c) 2012-2016 MADE/YOUR/DAY
*
*	Permission is hereby granted, free of charge, to any person obtaining a copy
*	of this software and associated documentation files (the "Software"), to deal
*	in the Software without restriction, including without limitation the rights
*	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
*	copies of the Software, and to permit persons to whom the Software is furnished
*	to do so, subject to the following conditions:
*
*	The above copyright notice and this permission notice shall be included in all
*	copies or substantial portions of the Software.
*
*	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
*	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
*	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
*	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
*	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
*	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
*	THE SOFTWARE.
*/

namespace leycommediasolutions\FrontendBuilder;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\Database;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;
use leycommediasolutions\contao_elementsets\ElementSets;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;

use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function in_array;

class FrontendHooks
{
	private array $backendModules;
    private RequestStack $requestStack;
    private ScopeMatcher $scopeMatcher;
    private CsrfTokenManagerInterface $csrfTokenManager;
    private string $csrfTokenName;

    public function __construct(
        array $backendModules = [],
        CsrfTokenManagerInterface $csrfTokenManager,
        string $csrfTokenName,
        RequestStack $requestStack,
        ScopeMatcher $scopeMatcher,
        )
    {
        $this->csrfTokenManager = $csrfTokenManager;
        $this->csrfTokenName = $csrfTokenName;
        $this->requestStack = $requestStack;
        $this->scopeMatcher = $scopeMatcher;
        $this->backendModules = $backendModules;
    }
    public function isBackend(): bool
    {
        return $this->scopeMatcher->isBackendRequest($this->requestStack->getCurrentRequest());
    }

    public function isFrontend(): bool
    {
        return $this->scopeMatcher->isFrontendRequest($this->requestStack->getCurrentRequest());
    }
    public function generateToken(): string
    {
        return $this->csrfTokenManager->getToken($this->csrfTokenName)->getValue();
    }

    public function checkToken(string $tokenValue): bool
    {
        $token = new CsrfToken($this->csrfTokenName, $tokenValue);

        return $this->csrfTokenManager->isTokenValid($token);
    }
	/**
	 * parseFrontendTemplate hook
	 *
	 * @param string $content  html content
	 * @param string $template template name
	 * @return string           modified $content
	 */
	public function parseFrontendTemplateHook(string $content, string $template): string
    {
        if(!$template) {
            return $content;
        }

		if (!($permissions = static::checkLogin())) {
			return $content;
		}
		$data = array();
		if(preg_match('(<([a-zA-Z]+).*?>)is', $content, $tag) && $tag[1] !== 'script' ){
			$data = [
				'template' => $template,
            ];
			// get the first tag
			if (preg_match('(<[a-z0-9]+\\s(?>"[^"]*"|\'[^\']*\'|[^>"\'])+)is', $content, $matches)) {
				if (preg_match('(^(.*\\sid="[^"]*)article-([0-9]+))is', $matches[0], $matches2)) {
					$data['links']['article'] = array();
					System::loadLanguageFile('tl_content');
					$data['links']['pastenew'] = array(
						'url' => static::getBackendURL('article', 'tl_content', $matches2[2], 'create', array('mode' => 2, 'pid' => $matches2[2])),
						'label' => $GLOBALS['TL_LANG']['tl_content']['pastenew'][0],
					);
				}
			}

			return $this->insertData($content, $data);
		}
		return $content;
	}

	public function myGetArticle($objRow): void
    {
		$database = Database::getInstance();
		$cols = ['main'];

        $page = $database->prepare("SELECT * FROM tl_page WHERE id=?")
						 ->limit(1)
						 ->execute($objRow->pid);
						
		if($page->includeLayout && $page->layout){
			//LAYOUT WURDE VERGEBEN
		}else{
            $page_parent = null;
            while(!$page_parent->includeLayout && !$page_parent->layout){
				if($page_parent->id){
					$new_pid = $page_parent->id;
				}else{
					$new_pid = $page->pid;
				}
				$page_parent = $database->prepare("SELECT * FROM tl_page WHERE id=?")
										->limit(1)
										->execute($new_pid);
				$layout_id = $page_parent->layout;
			}
		}
		$layout = $database->prepare("SELECT modules_frontendbuilder_cols, modules_frontendbuilder_row, modules_frontendbuilder_custom FROM tl_layout WHERE id=?")
							->limit(1)
							->execute($layout_id);

		if (in_array($layout->modules_frontendbuilder_row, array('2rwh', '3rw')))
		{
			$cols[] = 'header';
		}

		if (in_array($layout->modules_frontendbuilder_cols, array('2cll', '3cl')))
		{
			$cols[] = 'left';
		}

		if (in_array($layout->modules_frontendbuilder_cols, array('2clr', '3cl')))
		{
			$cols[] = 'right';
		}

		if (in_array($layout->modules_frontendbuilder_row, array('2rwf', '3rw')))
		{
			$cols[] = 'footer';
		}
		if ($layout->modules_frontendbuilder_custom)
		{

			$custom_modules = StringUtil::deserialize($layout->modules_frontendbuilder_custom, true);
			for($ix=0, $ixMax = count($custom_modules); $ix< $ixMax; $ix++){
				$cols[] = $custom_modules[$ix];
			}
		}
		if($objRow->inColumn && in_array($objRow->inColumn, $cols, true)) {
            $arrCSS = StringUtil::deserialize($objRow->cssID, true);
            // use trim() to remove the leading space if $arrCSS[1] is empty
            $arrCSS[1] = trim($arrCSS[1] . ' fbly_accept');
            $objRow->cssID = serialize($arrCSS);
        }
	}

	/**
	 * outputFrontendTemplate hook
	 *
	 * @param  string $content  html content
	 * @param  string $template template name
	 * @return string           modified $content
	 */
	public function outputFrontendTemplateHook($content, $template)
	{
		if (!($permissions = static::checkLogin()) || !$template || substr($template, 0, 3) !== 'fe_' ) {
			return $content;
		}
        $data = array();
		$assetsDir = 'bundles/frontendbuilder';

		$GLOBALS['TL_JAVASCRIPT'][] = $assetsDir . '/js/main.js';
		$GLOBALS['TL_JAVASCRIPT'][] = $assetsDir . '/js/functions.js';
		$GLOBALS['TL_CSS'][] = $assetsDir . '/css/main.min.css';

		return static::insertData($content, $data);
	}

	/**
	 * getContentElement hook
	 *
	 * @param  Object $row     content element
	 * @param  string $content html content
	 * @return string          modified $content
	 */
	public function getContentElementHook($row, $content, $element)
	{	
		if (! $permissions = static::checkLogin()) {
			return $content;
		}
        $data = array();
		$do = 'article';
		if ($row->ptable) {
			foreach ($GLOBALS['BE_MOD'] as $category) {
				foreach ($category as $moduleName => $module) {
					if (
						! empty($module['tables']) &&
						in_array($row->ptable, $module['tables']) &&
						in_array('tl_content', $module['tables'])
					) {
						$do = $moduleName;
						break 2;
					}
				}
			}
		}

		System::loadLanguageFile('tl_content');
		$data['links']['pastenew'] = array(
			'url' => static::getBackendURL($do, 'tl_content', $row->pid, 'create', array('mode' => 1, 'pid' => $row->id)),
			'label' => sprintf($GLOBALS['TL_LANG']['tl_content']['pastenew'][1], $row->id),
		);

		return $this->insertData($content, $data);
	}

	/**
	 * checks if a Backend User is logged in
	 *
	 * @return boolean false if the user isn't logged in otherwise the permissions array
	 */
	public function checkLogin(): bool
	{
		// Only try to authenticate in the front end
		if ($this->isBackend()) {
			return false;
		}

		// Do not create a user instance if there is no authentication cookie
		if (! is_subclass_of('BackendUser', UserInterface::class) && ! Input::cookie('BE_USER_AUTH')) {
			return false;
		}
		$User = UserClass::getInstance();

		if (!$User->authenticate()) {
			return false;
		}
		if ($User->frontendbuilder) {
			return true;
		}

        if ($User->isAdmin) {
            return true;
        }

		return false;
	}

    /**
     * create backend edit URL
     *
     * @param string $do
     * @param string $table
     * @param string $id
     * @param string $act
     * @param array $params
     * @return string
     */
	protected function getBackendURL(string $do, string $table, string $id, string $act = 'edit', array $params = array()): string
    {
		$addParams = [];
		foreach (['do', 'table', 'act', 'id'] as $key) {
			if ($$key) {
				$addParams[$key] = $$key;
			}
		}

		// This is necessary because Contao wants the parameters to be in the right order.
		// E.g. `?node=2&do=article` doesn’t work while `?do=article&node=2` does.
		$params = array_merge($addParams, $params);

		$params['rt'] = $this->generateToken();
		$params['fblyr'] = 1;

		$url = System::getContainer()->get('router')->generate('contao_backend');

		// Third parameter is required because of arg_separator.output
		$url .= '?' . http_build_query($params, null, '&');

		return $url;
	}

	/**
	 * create the select pptions
	 * Compatibility for ce-access extension
	 * 
	 * @param array $frontenddata
	 * @param array $frontendElement
	 * @param string $v
	 * @param  string $kk
	 * @return string
	 */

	// if (class_exists(\leycommediasolutions\contao_elementsets\ElementSets::class)) {
	// }


	public function createSelect(array $frontenddata, array $frontendElement, array $v): string
    {
		foreach (array_keys($v) as $kk)
		{
			$editAllowed = true;
			if(class_exists('CeAccess') && !in_array($kk, (array)UserClass::getInstance()->elements, true) && !UserClass::getInstance()->isAdmin){
				$editAllowed = false;
			}
			if($editAllowed){
				$headline = "";
				$icon = "";
				$description = "";
				$alt = "";
				$tooltip = "";

				if($frontenddata[$kk] && $frontenddata[$kk]["headline"] != ""){
					$headline = $frontenddata[$kk]["headline"];
				}else if($frontendElement[$GLOBALS["TL_LANGUAGE"]][$kk] && $frontendElement[$GLOBALS["TL_LANGUAGE"]][$kk]["headline"] != ""){
					$headline = $frontendElement[$GLOBALS["TL_LANGUAGE"]][$kk]["headline"];
				}else{
					$headline = $GLOBALS['TL_LANG']['CTE'][$kk][0];
				}
				if(!$headline){
					$headline = $kk; 
				}

				if($frontenddata[$kk] && $frontenddata[$kk]["icon"] != ""){
					$icon = \FilesModel::findByUuid($frontenddata[$kk]["icon"])->path;
				}else if($frontendElement[$GLOBALS["TL_LANGUAGE"]][$kk] && $frontendElement[$GLOBALS["TL_LANGUAGE"]][$kk]["icon"] != ""){
					if(strpos($frontendElement[$GLOBALS["TL_LANGUAGE"]][$kk]["icon"], "/" ) === false){
						$icon = "bundles/frontendbuilder/icons/" . $frontendElement[$GLOBALS["TL_LANGUAGE"]][$kk]["icon"];
					}else{
						$icon = $frontendElement[$GLOBALS["TL_LANGUAGE"]][$kk]["icon"];
					}
				}else{
					if($frontenddata[$kk] && $frontenddata[$kk]["icon"] != ""){
						$icon = \FilesModel::findByUuid($frontenddata[$kk]["icon"])->path;
					}else if($frontendElement["de"][$kk] && $frontendElement["de"][$kk]["icon"] != ""){
						if(strpos($frontendElement["de"][$kk]["icon"], "/" ) === false){
							$icon = "bundles/frontendbuilder/icons/" . $frontendElement["de"][$kk]["icon"];
						}else{
							$icon = $frontendElement["de"][$kk]["icon"];
						}
					}else{
						
						$icon = "bundles/frontendbuilder/icons/icon.svg";
					}
				}

				if($frontenddata[$kk] && $frontenddata[$kk]["description"] != ""){
					$description = $frontenddata[$kk]["description"];
				}else if($frontendElement[$GLOBALS["TL_LANGUAGE"]][$kk] && $frontendElement[$GLOBALS["TL_LANGUAGE"]][$kk]["description"] != ""){
					$description = $frontendElement[$GLOBALS["TL_LANGUAGE"]][$kk]["description"];
				}else{
					$description = "";
				}


				if($frontenddata[$kk] && $frontenddata[$kk]["alt"] != ""){
					$alt = $frontenddata[$kk]["alt"];
				}else if($frontendElement[$GLOBALS["TL_LANGUAGE"]][$kk] && $frontendElement[$GLOBALS["TL_LANGUAGE"]][$kk]["alt"] != ""){
					$alt = $frontendElement[$GLOBALS["TL_LANGUAGE"]][$kk]["alt"];
				}else{
					$alt = $headline;
				}

				if($description){
					$tooltip .= 'title="'.$description.'"';
				}


				$output .= 
				'<div class="fbly_select_item draggable_item tooltip" data-value="'.$kk.'" draggable="true" ondragstart="event.dataTransfer.setData(\'text/plain\',null)" '.$tooltip.'>
					<div class="headline matchHeight"><span>'.$headline.'</span></div>
					<figure class="image_container"><img src="'.$icon.'" alt="'.$alt.'" draggable="false"></figure>
				</div>'; //					<div class="description"  class="tooltip" title="This is my images tooltip message!">'.$description.'</div>
			}
		}
		if($output){
			return $output;
		}
		return "";
	}
	/**
	 * @return string finish sidebar
	 */
	public function createDataSidebar(){
		if (!($permissions = static::checkLogin())) {
			return "";
		}

		//FEHLER
		System::loadLanguageFile('default');
		System::loadLanguageFile('CTE');
		System::loadLanguageFile('MOD');
		System::loadLanguageFile('FMD');
		System::loadLanguageFile('modules');

		$User = UserClass::getInstance();

		$output = "";

		$frontendElement = $this->backendModules;
		$frontendtext = Database::getInstance()
			->prepare("SELECT * FROM tl_frontendbuilder WHERE language=?")
			->execute($GLOBALS["TL_LANGUAGE"]);

		$frontenddata = array();
		while($frontendtext->next()){
			$frontenddata[$frontendtext->item] = $frontendtext->row();
		} 






		foreach ($GLOBALS['TL_CTE'] as $k=>$v)
		{
			if(array_keys($v)){
				if($GLOBALS['TL_LANG']['CTE'][$k]){
					$label_group = $GLOBALS['TL_LANG']['CTE'][$k];
				}
				else{
					$label_group = $k;
				}

				$pre = array('/\s/', '/\//', '/\*/', '/\+/', '/\#/', '/\~/', '/\"/', '/\§/', '/\$/', '/\!/', '/\%/', '/\&/', '/\(/', '/\)/', '/\=/', '/\?/', '/\´/', '/\{/', '/\[/', '/\]/', '/\}/', '/\>/', '/\</', '/\|/', '/\:/', '/\./', '/\,/', '/\;/');

				if(class_exists(ElementSets::class) && $k === 'elementset' ){
					if($content_select = FrontendHooks::createSelect($frontenddata, $frontendElement,$v)){
						$output .= '<div class="fbly_select_itemHolder fbly_select_close_'. preg_replace($pre, '_', $k) .'"><h3><span>'. $label_group  .'</span></h3>';
						$output .= '<div class="inside_fbly_select_itemHolder">';
						$output .= $content_select;
						$output .= '</div>';

						$database = \Database::getInstance();
				
						$elementsets = $database->prepare("SELECT * FROM tl_elementsets")->execute();

						if($elementsets->numRows > 0){
							while($elementsets->next())
							{
								$array_elementset[$elementsets->category][] = $elementsets->row();
							}
						}

						foreach ($array_elementset as $name => $attr ){
							$output .= '<div class="fbly_select_itemHolder fbly_select_close_'. preg_replace($pre, '_', $k) .'_'.preg_replace($pre, '_',strtolower($name)) . '"><h3><span>'. $name  .'</span></h3>';
							$output .= '<div class="inside_fbly_select_itemHolder">';
								foreach($attr as $attr_detail){
									$icon = FilesModel::findByUuid($attr_detail["preview_image"])->path;
									$output .= 
									'<div class="fbly_select_item draggable_item" data-value="elementset" data-elementset="'.$attr_detail["id"].'" draggable="true" ondragstart="event.dataTransfer.setData(\'text/plain\',null)">
										<div class="headline matchHeight"><span>'.$attr_detail["title"].'</span></div>
										<figure class="image_container"><img src="'.$icon.'" alt="'.$attr_detail["title"].'" draggable="false"></figure>
									</div>';
								}
							$output .= '</div>';
							$output .= '</div>';								
						}						
						$output .= '</div>';
					}
				}

				if(class_exists(ElementSets::class) && $k != 'elementset'){
					if($content_select = FrontendHooks::createSelect($frontenddata, $frontendElement,$v)){
						$output .= '<div class="fbly_select_itemHolder fbly_select_close_'. preg_replace($pre, '_', $k) .'"><h3><span>'. $label_group  .'</span></h3>';
						$output .= '<div class="inside_fbly_select_itemHolder">';
						$output .= $content_select;
						$output .= '</div>';
						$output .= '</div>';
					}
				}

				if(!class_exists(ElementSets::class)){
					if($content_select = FrontendHooks::createSelect($frontenddata, $frontendElement,$v)){
						$output .= '<div class="fbly_select_itemHolder fbly_select_close_'. preg_replace('/\s/', '_', $k) .'"><h3><span>'. $label_group  .'</span></h3>';
						$output .= '<div class="inside_fbly_select_itemHolder">';
						$output .= $content_select;
						$output .= '</div>';
						$output .= '</div>';
					}
				}
			}
		}

		return 					
		'
		<!-- indexer::stop -->
		<div id="fbly" class="fbly_sidebar">
			<div id="fbly_open_button" class="open"><span class="h1">Contao Frontend Builder</span></div>
			<div class="fbly_inside">
				<div id="fbly_header" class="fbly_header">
					<div class="fbly_header_inside">
						<div class="fbly_logo">
							<a href="">Contao</a>
						</div>
						<nav aria-label="Header-Navigation">
							<ul id="tmenu">
								<li><a href="'.static::getBackendURL(null, null, null, null).'" target="_blank">Backend</a></li>
								<li class="submenu">
									<h2>'.$GLOBALS['TL_LANG']['MSC']['user'].' '.$User->username.'</h2>
									<ul class="level_2">
										<li class="info"><strong>'.$User->name.'</strong> '.$User->email.'</li>
										<li><a href="'.static::getBackendURL("login", null, null, null).'" class="icon-profile" target="_blank">'.$GLOBALS['TL_LANG']['MSC']['profile'].'</a></li>
										<li><a href="'.static::getBackendURL("security", null, null, null).'" class="icon-security" target="_blank">'.$GLOBALS['TL_LANG']['MSC']['security'].'</a></li>
										<li><a href="/contao/logout" class="icon-logout">'.$GLOBALS['TL_LANG']['PTY']['logout'][0].'</a></li>
									</ul>
								</li>
							</ul>
						</nav>
					</div>
				</div>
				<div class="fbly_body">	
					<div class="fbly_body_inside">
					<div class="fbly_divider"></div>
						<div id="fbly_type" class="fbly_type">
							<div id="fbly_select" class="fbly_select tl_chosen tl_select">
								<div class="fbly_select_inside">'.
								$output
								.'</div>
							</div>
						</div>
						<div id="fbly_footer" class="fbly_footer">
							<div class="fbly_company">
								<a href="https://www.leycom.de/" target="_blank">
									<span>leycom - media solutions</span>
								</a>
							</div>
							<div class="fbly_image">
								<a href="https://www.leycom.de/" target="_blank">
									<figure class="image_container">
										<img src="bundles/frontendbuilder/img/leycom_small_logo.svg">
									</figure>
								</a>
							</div>
							<div class="fbly_version">
								<span>Version '.$GLOBALS['frontendbuilder']['version'].'</span>
							</div>
						</div>
					</div>
				</div>
			</div>					
		</div>
		<div id="fbly_iframe"><div id="fbly_iframe_headline"><h2>Contao Backend</h2></div><div id="fbly_iframe_closeButton">X</div><iframe id="fbly_iframe_iframe"></iframe><div id="fbly_preloader" class=""><div class="sk-folding-cube"><div class="sk-cube1 sk-cube"></div><div class="sk-cube2 sk-cube"></div><div class="sk-cube4 sk-cube"></div><div class="sk-cube3 sk-cube"></div></div></div></div>
		<!-- indexer::continue -->
		';
	}

	/**
	 * inserts data into the first tag in $content as data-createElement
	 * attribute in json format and merges existing data
	 *
	 * @param  string $content
	 * @param  array  $data
	 * @return string
	 */
	protected function insertData($content, $data)
	{
		if (preg_match('(^.*?<([a-z0-9]+)(?:\\s(?>"[^"]*"|\'[^\']*\'|[^>"\'])+|))is', $content, $matches) && $matches[1] !== 'esi') {
			if(preg_match('(<([a-z0-9]+).*?>)is', $content, $tag) && $tag[1] === "body" ){
				if(preg_match('(<div id="(fbly).*?>)is', $content, $class) && $class[1] === "fbly"){
				}else{
					$content_temp = explode($tag[0], $content, 2);
					$content = $tag[0].
					FrontendHooks::createDataSidebar()
					. $content_temp[1]; 
				}

			}

			if ($matches[1] === 'html' && strpos($content, '<body') !== -1 ) {
				$content = explode('<body', $content, 2);
				return $content[0] . static::insertData('<body' . $content[1], $data);
			}

			$content = substr($content, strlen($matches[0]));

			if (preg_match('(\\sdata-createElement="([^"]*)")is', $matches[0], $matches2)) {
				$oldData = json_decode(html_entity_decode($matches2[1]), true);
				if (!is_array($oldData)) {
					$oldData = array();
				}
				if (isset($oldData['links']) && isset($data['links'])) {
					$data['links'] = array_merge($oldData['links'], $data['links']);
                }
				if(count($data) > 0){
					$data = array_merge($oldData, $data);
					$matches[0] = preg_replace('(\\sdata-createElement="([^"]*)")is', '', $matches[0]);
				}
			}
			if(count($data) > 0){
				return $matches[0] . ' data-createElement="' . htmlspecialchars(json_encode($data)) . '"' . $content;
			}else{
				return $matches[0] . $content;
			}
		}

		return $content;
	}
}

<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic, NP. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.com
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

//@todo - fix issue where associations are not populating immediately after an edit

namespace Mautic\ReportBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\ReportBundle\Form\FormBuilder;
use Mautic\ReportBundle\Generator\ReportGenerator;
use Symfony\Component\HttpFoundation\JsonResponse;

class ReportController extends FormController
{

    /**
     * @param int    $page
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction($page = 1)
    {
	    /* @type \Mautic\ReportBundle\Model\ReportModel $model */
        $model = $this->factory->getModel('report');

        //set some permissions
        $permissions = $this->factory->getSecurity()->isGranted(array(
            'report:reports:viewown',
            'report:reports:viewother',
            'report:reports:create',
            'report:reports:editown',
            'report:reports:editother',
            'report:reports:deleteown',
            'report:reports:deleteother',
            'report:reports:publishown',
            'report:reports:publishother'
        ), "RETURN_ARRAY");

        if (!$permissions['report:reports:viewown'] && !$permissions['report:reports:viewother']) {
            return $this->accessDenied();
        }

        //set limits
        $limit = $this->factory->getSession()->get('mautic.report.limit', $this->factory->getParameter('default_pagelimit'));
        $start = ($page === 1) ? 0 : (($page-1) * $limit);
        if ($start < 0) {
            $start = 0;
        }

        $search = $this->request->get('search', $this->factory->getSession()->get('mautic.report.filter', ''));
        $this->factory->getSession()->set('mautic.report.filter', $search);

        $filter = array('string' => $search, 'force' => array());

        if (!$permissions['report:reports:viewother']) {
            $filter['force'][] =
                array('column' => 'r.createdBy', 'expr' => 'eq', 'value' => $this->factory->getUser());
        }

	    /* @type \Symfony\Bundle\FrameworkBundle\Translation\Translator $translator */
        $translator = $this->get('translator');
        //do not list variants in the main list
        //$filter['force'][] = array('column' => 'r.variantParent', 'expr' => 'isNull');

        $langSearchCommand = $translator->trans('mautic.report.report.searchcommand.lang');
        if (strpos($search, "{$langSearchCommand}:") === false) {
            //$filter['force'][] = array('column' => 'r.translationParent', 'expr' => 'isNull');
        }

        $orderBy     = $this->factory->getSession()->get('mautic.report.orderby', 'r.title');
        $orderByDir  = $this->factory->getSession()->get('mautic.report.orderbydir', 'DESC');

        $reports = $model->getEntities(
            array(
                'start'      => $start,
                'limit'      => $limit,
                'filter'     => $filter,
                'orderBy'    => $orderBy,
                'orderByDir' => $orderByDir
            )
        );

        $count = count($reports);
        if ($count && $count < ($start + 1)) {
            //the number of entities are now less then the current page so redirect to the last page
            if ($count === 1) {
                $lastPage = 1;
            } else {
                $lastPage = (floor($limit / $count)) ? : 1;
            }
            $this->factory->getSession()->set('mautic.report.report', $lastPage);
            $returnUrl   = $this->generateUrl('mautic_report_index', array('page' => $lastPage));

            return $this->postActionRedirect(array(
                'returnUrl'       => $returnUrl,
                'viewParameters'  => array('page' => $lastPage),
                'contentTemplate' => 'MauticReportBundle:Report:index',
                'passthroughVars' => array(
                    'activeLink'    => '#mautic_report_index',
                    'mauticContent' => 'report'
                )
            ));
        }

        //set what page currently on so that we can return here after form submission/cancellation
        $this->factory->getSession()->set('mautic.report.report', $page);

        $tmpl = $this->request->isXmlHttpRequest() ? $this->request->get('tmpl', 'index') : 'index';

        return $this->delegateView(array(
            'viewParameters'  =>  array(
                'searchValue' => $search,
                'items'       => $reports,
                'page'        => $page,
                'limit'       => $limit,
                'permissions' => $permissions,
                'model'       => $model,
                'tmpl'        => $tmpl,
                'security'    => $this->factory->getSecurity()
            ),
            'contentTemplate' => 'MauticReportBundle:Report:list.html.php',
            'passthroughVars' => array(
                'activeLink'     => '#mautic_report_index',
                'mauticContent'  => 'report',
                'route'          => $this->generateUrl('mautic_report_index', array('page' => $page)),
                'replaceContent' => ($tmpl == 'list') ? 'true' : 'false'
            )
        ));
    }

    /**
     * Generates edit form and processes post data
     *
     * @param integer $objectId   Item ID
     * @param boolean $ignorePost Flag to ignore POST data
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function editAction ($objectId, $ignorePost = false)
    {
        /* @type \Mautic\ReportBundle\Model\ReportModel $model */
        $model      = $this->factory->getModel('report');
        $entity     = $model->getEntity($objectId);
        $session    = $this->factory->getSession();
        $page       = $session->get('mautic.report.report', 1);

        //set the return URL
        $returnUrl  = $this->generateUrl('mautic_report_index', array('page' => $page));

        $postActionVars = array(
            'returnUrl'       => $returnUrl,
            'viewParameters'  => array('page' => $page),
            'contentTemplate' => 'MauticReportBundle:Report:index',
            'passthroughVars' => array(
                'activeLink'    => 'mautic_report_index',
                'mauticContent' => 'report'
            )
        );

        //not found
        if ($entity === null) {
            return $this->postActionRedirect(
                array_merge($postActionVars, array(
                    'flashes' => array(
                        array(
                            'type' => 'error',
                            'msg'  => 'mautic.report.report.error.notfound',
                            'msgVars' => array('%id%' => $objectId)
                        )
                    )
                ))
            );
        }  elseif (!$this->factory->getSecurity()->hasEntityAccess(
            'report:reports:viewown', 'report:reports:viewother', $entity->getCreatedBy()
        )) {
            return $this->accessDenied();
        } elseif ($model->isLocked($entity)) {
            //deny access if the entity is locked
            return $this->isLocked($postActionVars, $entity, 'report.report');
        }

        //Create the form
        $action = $this->generateUrl('mautic_report_action', array('objectAction' => 'edit', 'objectId' => $objectId));
        $form   = $model->createForm($entity, $this->get('form.factory'), $action);

        ///Check for a submitted form and process it
        if (!$ignorePost && $this->request->getMethod() == 'POST') {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    //form is valid so process the data
                    $model->saveEntity($entity, $form->get('buttons')->get('save')->isClicked());

                    //clear the session
                    $session->remove($contentName);

                    $this->request->getSession()->getFlashBag()->add(
                        'notice',
                        $this->get('translator')->trans('mautic.report.report.notice.updated', array(
                            '%name%' => $entity->getTitle(),
                            '%url%'  => $this->generateUrl('mautic_report_action', array(
                                'objectAction' => 'edit',
                                'objectId'     => $entity->getId()
                            ))
                        ), 'flashes')
                    );

                    $returnUrl = $this->generateUrl('mautic_report_action', array(
                        'objectAction' => 'view',
                        'objectId'     => $entity->getId()
                    ));
                    $viewParams = array('objectId' => $entity->getId());
                    $template = 'MauticReportBundle:Report:view';
                }
            } else {
                //unlock the entity
                $model->unlockEntity($entity);

                $returnUrl = $this->generateUrl('mautic_report_index', array('page' => $page));
                $viewParams = array('report' => $page);
                $template  = 'MauticReportBundle:Report:index';
            }

            if ($cancelled || ($valid && $form->get('buttons')->get('save')->isClicked())) {
                return $this->postActionRedirect(
                    array_merge($postActionVars, array(
                        'returnUrl'       => $returnUrl,
                        'viewParameters'  => $viewParams,
                        'contentTemplate' => $template
                    ))
                );
            }
        } else {
            //lock the entity
            $model->lockEntity($entity);
        }

        return $this->delegateView(array(
            'viewParameters'  =>  array(
                'report'      => $entity,
                'form'        => $form->createView()
            ),
            'contentTemplate' => 'MauticReportBundle:Report:form.html.php',
            'passthroughVars' => array(
                'activeLink'    => '#mautic_report_index',
                'mauticContent' => 'report',
                'route'         => $this->generateUrl('mautic_report_action', array(
                    'objectAction' => 'edit',
                    'objectId'     => $entity->getId()
                ))
            )
        ));
    }

    /**
     * Shows a report
     *
     * @param string $reportId Report ID
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     *
     * @author r1pp3rj4ck <attila.bukor@gmail.com>
     */
    public function viewAction($reportId)
    {
        /* @type \Mautic\ReportBundle\Model\ReportModel $model */
        $model      = $this->factory->getModel('report');
        $entity     = $model->getEntity($reportId);

        //set the report we came from
        $page = $this->factory->getSession()->get('mautic.report.report', 1);

        if ($entity === null) {
            //set the return URL
            $returnUrl = $this->generateUrl('mautic_report_index', array('page' => $page));

            return $this->postActionRedirect(array(
                'returnUrl'       => $returnUrl,
                'viewParameters'  => array('page' => $page),
                'contentTemplate' => 'MauticReportBundle:Report:index',
                'passthroughVars' => array(
                    'activeLink'    => '#mautic_report_index',
                    'mauticContent' => 'report'
                ),
                'flashes'         => array(
                    array(
                        'type'    => 'error',
                        'msg'     => 'mautic.report.report.error.notfound',
                        'msgVars' => array('%id%' => $reportId)
                    )
                )
            ));
        } elseif (!$this->factory->getSecurity()->hasEntityAccess(
            'report:reports:viewown', 'report:reports:viewother', $entity->getCreatedBy()
        )
        ) {
            return $this->accessDenied();
        }

        $reportGenerator = new ReportGenerator(
            $this->factory->getEntityManager(), $this->factory->getSecurityContext(), new FormBuilder($this->container->get('form.factory'))
        );

        // The report builder is referencing reports by name right now, get it
        $reportName = str_replace(' ', '', $entity->getTitle());

        $query = $reportGenerator->getQuery($reportName);

        $form = $reportGenerator->getForm($reportName, array('read_only' => true));

        if ($this->request->getMethod() == 'POST') {
            $form->bindRequest($this->request);

            $query->setParameters($form->getData());
        }

        $modifiers = $reportGenerator->getModifiers($reportName);

        $result = $query->getResult();

        foreach ($result as &$outer) {
                foreach ($outer as $key => &$value) {
                    if (array_key_exists($key, $modifiers) && $value) {
                        $value = call_user_func_array(array($value, $modifiers[$key]['method']), $modifiers[$key]['params']);
                }
            }
        }

        return $this->delegateView(array(
            'viewParameters'  =>  array(
                'result' => $result,
                'report' => $entity
            ),
            'contentTemplate' => 'MauticReportBundle:Report:details.html.php',
            'passthroughVars' => array(
                'activeLink'     => '#mautic_report_index',
                'mauticContent'  => 'report',
                'route'         => $this->generateUrl('mautic_report_action', array(
                    'objectAction' => 'view',
                    'objectId'     => $entity->getId()
                ))
            )
        ));
    }
}

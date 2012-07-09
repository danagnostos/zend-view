<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_View
 */

namespace Zend\View;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\Mvc\MvcEvent;
use Zend\Stdlib\RequestInterface as Request;
use Zend\Stdlib\ResponseInterface as Response;
use Zend\View\Renderer\RendererInterface as Renderer;
use Zend\View\Model\ModelInterface as Model;
use Zend\View\Renderer\TreeRendererInterface;

/**
 * @category   Zend
 * @package    Zend_View
 */
class View implements EventManagerAwareInterface
{
    /**
     * @var EventManagerInterface
     */
    protected $events;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Response
     */
    protected $response;

    /**
     * Set MVC request object
     *
     * @param  Request $request
     * @return View
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
        return $this;
    }

    /**
     * Set MVC response object
     *
     * @param  Response $response
     * @return View
     */
    public function setResponse(Response $response)
    {
        $this->response = $response;
        return $this;
    }

    /**
     * Get MVC request object
     *
     * @return null|Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Get MVC response object
     *
     * @return null|Response
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Set the event manager instance
     *
     * @param  EventManagerInterface $events
     * @return View
     */
    public function setEventManager(EventManagerInterface $events)
    {
        $events->setIdentifiers(array(
            __CLASS__,
            get_called_class(),
        ));
        $this->events = $events;
        return $this;
    }

    /**
     * Retrieve the event manager instance
     *
     * Lazy-loads a default instance if none available
     *
     * @return EventManagerInterface
     */
    public function getEventManager()
    {
        if (!$this->events instanceof EventManagerInterface) {
            $this->setEventManager(new EventManager());
        }
        return $this->events;
    }

    /**
     * Add a rendering strategy
     *
     * Expects a callable. Strategies should accept a ViewEvent object, and should
     * return a Renderer instance if the strategy is selected.
     *
     * Internally, the callable provided will be subscribed to the "renderer"
     * event, at the priority specified.
     *
     * @param  callable $callable
     * @param  int $priority
     * @return View
     */
    public function addRenderingStrategy($callable, $priority = 1)
    {
        $this->getEventManager()->attach(ViewEvent::EVENT_RENDERER, $callable, $priority);
        return $this;
    }

    /**
     * Add a response strategy
     *
     * Expects a callable. Strategies should accept a ViewEvent object. The return
     * value will be ignored.
     *
     * Typical usages for a response strategy are to populate the Response object.
     *
     * Internally, the callable provided will be subscribed to the "response"
     * event, at the priority specified.
     *
     * @param  callable $callable
     * @param  int $priority
     * @return View
     */
    public function addResponseStrategy($callable, $priority = 1)
    {
        $this->getEventManager()->attach(ViewEvent::EVENT_RESPONSE, $callable, $priority);
        return $this;
    }

    /**
     * Render the provided model.
     *
     * Internally, the following workflow is used:
     *
     * - Trigger the "renderer" event to select a renderer.
     * - Call the selected renderer with the provided Model
     * - Trigger the "response" event
     *
     * @triggers renderer(ViewEvent)
     * @triggers response(ViewEvent)
     * @param  Model $model
     * @return void
     */
    public function render(Model $model)
    {
        $event   = $this->getEvent();
        $event->setModel($model);
        $events  = $this->getEventManager();
        $results = $events->trigger(ViewEvent::EVENT_RENDERER, $event, function($result) {
            return ($result instanceof Renderer);
        });
        $renderer = $results->last();
        if (!$renderer instanceof Renderer) {
            throw new Exception\RuntimeException(sprintf(
                '%s: no renderer selected!',
                __METHOD__
            ));
        }

        // If we have children, render them first, but only if:
        // a) the renderer does not implement TreeRendererInterface, or
        // b) it does, but canRenderTrees() returns false
        if ($model->hasChildren()
            && (!$renderer instanceof TreeRendererInterface
                || !$renderer->canRenderTrees())
        ) {
            $this->renderChildren($model);
        }

        // Reset the model, in case it has changed, and set the renderer
        $event->setModel($model);
        $event->setRenderer($renderer);

        $rendered = $renderer->render($model);

        // If this is a child model, return the rendered content; do not
        // invoke the response strategy.
        $options = $model->getOptions();
        if (array_key_exists('has_parent', $options) && $options['has_parent']) {
            return $rendered;
        }

        $event->setResult($rendered);

        $events->trigger(ViewEvent::EVENT_RESPONSE, $event);
    }

    /**
     * Loop through children, rendering each
     *
     * @param  Model $model
     * @return void
     */
    protected function renderChildren(Model $model)
    {
        foreach ($model as $child) {
            if ($child->terminate()) {
                throw new Exception\DomainException('Inconsistent state; child view model is marked as terminal');
            }
            $child->setOption('has_parent', true);
            $result  = $this->render($child);
            $child->setOption('has_parent', null);
            $capture = $child->captureTo();
            if (!empty($capture)) {
                if($child->isAppend()) {
                    $oldResult=$model->{$capture};
                    $model->setVariable($capture, $oldResult.$result);
                }
                else {
                    $model->setVariable($capture, $result);
                }
            }
        }
    }

    /**
     * Create and return ViewEvent used by render()
     *
     * @return ViewEvent
     */
    protected function getEvent()
    {
        $event = new ViewEvent();
        $event->setTarget($this);
        if (null !== ($request = $this->getRequest())) {
            $event->setRequest($request);
        }
        if (null !== ($response = $this->getResponse())) {
            $event->setResponse($response);
        }
        return $event;
    }
}

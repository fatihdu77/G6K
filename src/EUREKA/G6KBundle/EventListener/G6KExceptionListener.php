<?php

namespace EUREKA\G6KBundle\EventListener;

use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpFoundation\Request;

class G6KExceptionListener
{
	protected $kernel;

	public function __construct(Kernel $kernel) {
		$this->kernel = $kernel;
	}

	public function onKernelException(GetResponseForExceptionEvent $event) {
		$request = $event->getRequest();
		$exception = $event->getException();
		$route = $request->get("_route");
		if ($route == 'eureka_g6k_api') {
			$response = $this->jsonResponse($request, $exception);
		} elseif (preg_match("/^eureka_g6k_admin/", $route)) {
			$response = $this->htmlAdminResponse($exception);
		} else {
			$response = $this->htmlResponse($request, $exception);
		}
		$event->setResponse($response);
	}

	protected function htmlResponse(Request $request, \Exception $exception) {
		$domainview = $this->kernel->getContainer()->getParameter('domainview');
		$domain = $request->getHost();
		$view = $request->get("view", "");
		if ($view == "") {
			foreach ($domainview as $d => $v) {
				if (preg_match("/".$d."$/", $domain)) {
					$view = $v;
					break;
				}
			}
			if ($view == "") {
				$view = "Default";
			}
		}
		$template = <<<EOT
{% extends "EUREKAG6KBundle:{$view}/layout:pagelayout.html.twig" %}
{% block content %}
{% if app.user and is_granted('ROLE_ADMIN') %}
<div class="exception alert alert-danger has-error">
<span class="help-block">
{{ 'Error'|trans }} : {{ message }}
</span>
<span class="help-block">
Code : {{ code }}
</span>
</div>
<div class="alert alert-danger has-error">
<span class="help-block">
{{ stacktrace|jscode }}
</span>
</div>
{% else %}
<div class="exception alert alert-danger has-error">
<span class="help-block">
{{ 'The simulation engine is currently under maintenance'|trans }}
</span>
<span class="help-block">
{{ 'Please retry later'|trans }}
</span>
</div>
{% endif %}
{% endblock %}
EOT;
		$twig = $this->kernel->getContainer()->get('templating');

		$response = new Response();
		$response->setContent(
			$twig->render(
				$template, 
				array(
					'message' => $this->trace($exception),
					'stacktrace' => str_replace("\n", "<br>", $exception->getTraceAsString()),
					'code' => $exception->getCode()
				)
			)
		);

		if ($exception instanceof HttpExceptionInterface) {
			$response->setStatusCode($exception->getStatusCode());
			$response->headers->replace($exception->getHeaders());
		} else {
			$response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
		}
		return $response;
	}

	protected function htmlAdminResponse(\Exception $exception) {
		$twig = $this->kernel->getContainer()->get('templating');
		$response = new Response();
		$response->setContent(
			$twig->render(
				'EUREKAG6KBundle:admin/pages:exception.html.twig',
				array(
					'message' => $this->trace($exception),
					'stacktrace' => str_replace("\n", "<br>", $exception->getTraceAsString()),
					'code' => $exception->getCode()
				)
			)
		);

		if ($exception instanceof HttpExceptionInterface) {
			$response->setStatusCode($exception->getStatusCode());
			$response->headers->replace($exception->getHeaders());
		} else {
			$response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
		}
		return $response;
	}

	protected function jsonResponse(Request $request, \Exception $exception) {
		$simu = $request->get("simu", "");
		$errors = array();
		$errors[] = array(
			'status' => "" . Response::HTTP_UNPROCESSABLE_ENTITY,
			'title' => "Unprocessable entity",
			'detail' => $exception->getMessage(),
			'source' => array(
				'pointer' => "/data/" . $simu
			)
		);
		$self = $request->getSchemeAndHttpHost() . $request->getBasePath() . $request->getPathInfo() . '?' . $request->getQueryString();
		$response = new Response();
		$response->headers->set('Content-Type', 'application/json');
		$response->setContent(
			json_encode(array(
					'links' => array(
						'self' => $self,
					),
					'errors' => $errors
				)
			)
		);
		$response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
		return $response;
	}

	protected function trace(\Exception $e, $seen = null) {
		$starter = $seen ? 'Caused by: ' : '';
		$result = array();
		if (!$seen) $seen = array();
		$trace  = $e->getTrace();
		$prev   = $e->getPrevious();
		$result[] = sprintf('%s%s: %s', $starter, get_class($e), $e->getMessage());
		$file = $e->getFile();
		$line = $e->getLine();
		while (true) {
			$current = "$file:$line";
			if (is_array($seen) && in_array($current, $seen)) {
				$result[] = sprintf(' ... %d more', count($trace)+1);
				break;
			}
			$result[] = sprintf(' at %s%s%s(%s%s%s)',
				count($trace) && array_key_exists('class', $trace[0]) ? str_replace('\\', '.', $trace[0]['class']) : '',
				count($trace) && array_key_exists('class', $trace[0]) && array_key_exists('function', $trace[0]) ? '.' : '',
				count($trace) && array_key_exists('function', $trace[0]) ? str_replace('\\', '.', $trace[0]['function']) : '(main)',
				$line === null ? $file : basename($file),
				$line === null ? '' : ':',
				$line === null ? '' : $line);
			if (is_array($seen))
				$seen[] = "$file:$line";
			if (!count($trace))
				break;
			$file = array_key_exists('file', $trace[0]) ? $trace[0]['file'] : 'Unknown Source';
			$line = array_key_exists('file', $trace[0]) && array_key_exists('line', $trace[0]) && $trace[0]['line'] ? $trace[0]['line'] : null;
			array_shift($trace);
		}
		$result = join("<br>", $result);
		if ($prev)
			$result  .= "<br>" . $this->trace($prev, $seen);
		return $result;
}
}

?>

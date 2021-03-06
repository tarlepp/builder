<?php

namespace atoum\builder\controllers\hook;

use atoum\builder\exceptions;
use atoum\builder\resque\broker;
use atoum\builder\resque\jobs\build;
use Psr\Log\LoggerInterface;
use Swagger\Annotations as SWG;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Validator\Constraints;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @SWG\Model(
 *     id="Owner",
 *     required="name",
 *     @SWG\Property(name="name", type="string", description="Owner name")
 * )
 *
 * @SWG\Model(
 *     id="Repository",
 *     required="url, name",
 *     @SWG\Property(name="name", type="string", description="Repository name"),
 *     @SWG\Property(name="url", type="string", description="Repository HTTP URL"),
 *     @SWG\Property(name="owner", type="Owner", description="Repository owner")
 * )
 *
 * @SWG\Model(
 *     id="PushEvent",
 *     required="ref, head, repository",
 *     @SWG\Property(
 *         name="ref",
 *         type="string",
 *         description="Git reference"
 *     ),
 *     @SWG\Property(
 *         name="head",
 *         type="string",
 *         description="Git head SHA1"
 *     ),
 *     @SWG\Property(
 *         name="repository",
 *         type="Repository",
 *         description="Repository informations"
 *     )
 * )
 */

/**
 * @SWG\Resource(basePath="/")
 */
class push
{
	/**
	 * @var string
	 */
	private $token;

	/**
	 * @var broker
	 */
	private $broker;

	/**
	 * @var ValidatorInterface
	 */
	private $validator;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	public function __construct($token, broker $broker, ValidatorInterface $validator, LoggerInterface $logger)
	{
		$this->token = $token;
		$this->broker = $broker;
		$this->validator = $validator;
		$this->logger = $logger;
	}

	/**
	 * @SWG\Api(
	 *     path="/hook/push/{token}",
	 *     @SWG\Operation(
	 *         method="POST",
	 *         @SWG\Consumes("application/json"),
	 *         @SWG\Produces("application/json"),
	 *         @SWG\Parameter(
	 *             paramType="path",
	 *             type="string",
	 *             name="token",
	 *             required=true,
	 *             description="Authentication token"
	 *         ),
	 *         @SWG\Parameter(
	 *             paramType="body",
	 *             type="PushEvent",
	 *             name="body",
	 *             required=true,
	 *             description="Event payload"
	 *         ),
	 *         @SWG\ResponseMessage(
	 *             code=200,
	 *             message="Event acknowledged"
	 *         ),
	 *         @SWG\ResponseMessage(
	 *             code=400,
	 *             message="Invalid event payload"
	 *         ),
	 *         @SWG\ResponseMessage(
	 *             code=403,
	 *             message="Access denied"
	 *         )
	 *     )
	 * )
	 *
	 * @param string  $token
	 * @param Request $request
	 *
	 * @throws AccessDeniedHttpException
	 * @throws exceptions\validation
	 *
	 * @return Response
	 */
	public function __invoke($token, Request $request) : Response
	{
		$event = json_decode($request->getContent(false), true);

		if ($token !== $this->token)
		{
			throw new AccessDeniedHttpException();
		}

		$constraint = new Constraints\Collection([
			'allowExtraFields' => true,
			'fields' => [
				'ref' => new Constraints\Regex('#^refs/heads/#'),
				'head' => new Constraints\Regex('#^[0-9a-f]#i'),
				'repository' => new Constraints\Collection([
					'allowExtraFields' => true,
					'fields' => [
						'name' =>  new Constraints\NotBlank(),
						'url' => new Constraints\Regex('/^https?:\/\/.+$/'),
						'owner' => new Constraints\Collection([
							'allowExtraFields' => true,
							'fields' => [
								'name' =>  new Constraints\NotBlank()
							]
						])
					]
				])
			]
		]);

		$errors = $this->validator->validate($event, $constraint);

		if ($errors->count() > 0)
		{
			foreach ($errors as $error) {
				$this->logger->warning($error->getPropertyPath() . ' ' . $error->getMessage(), ['actual' => $error->getInvalidValue()]);
			}

			throw new exceptions\validation($errors);
		}

		return new JsonResponse($this->broker->enqueue(build::class, ['push' => $event]));
	}
}

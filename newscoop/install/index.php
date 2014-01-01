<?php
/**
 * @package Newscoop
 * @author Paweł Mikołajczuk <pawel.mikolajczuk@sourcefabric.org>
 * @copyright 2013 Sourcefabric o.p.s.
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

if (!file_exists(__DIR__ . '/../vendor') && !file_exists(__DIR__.'/../vendor/autoload.php')) {
	echo "Welcome in Newscoop Installer.<br/><br/>";
    echo "Looks like you forget about our vendors. Please install all dependencies with Composer.";
    echo "<pre>curl -s https://getcomposer.org/installer | php <br/>php composer.phar install --no-dev</pre>";
    echo "After that please refresh that page. Thanks!";
    die;
}

require_once __DIR__.'/../vendor/autoload.php';
require_once dirname(__FILE__).'/SymfonyRequirements.php';

use Symfony\Component\HttpFoundation\Response;
use Silex\Provider\FormServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\GenericEvent;
use Newscoop\Installer\Services;
use Symfony\Component\Validator\Constraints as Assert;

$app = new Silex\Application();
$app->register(new Silex\Provider\SessionServiceProvider());
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());
$app->register(new Silex\Provider\ValidatorServiceProvider());
$app->register(new FormServiceProvider());
$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => __DIR__.'/../log/install.log',
));
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/Resources/views',
));
$app->register(new Silex\Provider\TranslationServiceProvider(), array(
    'locale_fallbacks' => array('en'),
));

$app['debug'] = true;

$app['bootstrap_service'] = $app->share(function () use ($app) {return new Services\BootstrapService($app['monolog']);});
$app['database_service'] = $app->share(function () use ($app) {return new Services\DatabaseService($app['monolog']);});
$app['demosite_service'] = $app->share(function () use ($app) {return new Services\DemositeService($app['monolog']);});
$app['finish_service'] = $app->share(function () use ($app) {return new Services\FinishService();});

$app['dispatcher']->addListener('newscoop.installer.bootstrap', $app['bootstrap_service']->makeDirectoriesWritable());

$app->before(function (Request $request) use ($app) {
    if ($request->request->has('db_config') || $app['session']->has('db_data')) {
    	$request_db_config = $request->request->get('db_config');
    	$session_db_data = $app['session']->get('db_data');
    	$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    		'db.options' => array(
	            'driver'    => 'pdo_mysql',
	            'host'      => $request_db_config['server_name'] ? : $session_db_data['server_name'],
	            'dbname'    => $request_db_config['database_name'] ? : $session_db_data['database_name'],
	            'user'      => $request_db_config['user_name'] ? : $session_db_data['user_name'],
	            'password'  => $request_db_config['user_password'] ? : $session_db_data['user_password'],
	            'port'		=> $request_db_config['server_port'] ? : $session_db_data['server_port'],
	            'charset'   => 'utf8',
	        )
		));
    }
}, Silex\Application::EARLY_EVENT);

$app->get('/', function (Silex\Application $app) {
	// TODO: check if newscoop is isntalled and show info that you can't install newscoop on already installed instance.
	
	$app['dispatcher']->dispatch('newscoop.installer.bootstrap', new GenericEvent());

	$directories = $app['bootstrap_service']->checkDirectories();
	
	$symfonyRequirements = new SymfonyRequirements();
	$requirements = $symfonyRequirements->getRequirements();

	if ($directories !== true) {
		return $app['twig']->render('botstrap_errors.twig', array('directories' => $directories));
	}

	$checkPassed = true;
	foreach ($requirements as $req) {
	    if (!$req->isFulfilled()) {
	        $checkPassed = false;
	    }
	}

	return $app['twig']->render('index.twig', array(
		'requirements' => $requirements,
		'recommendations' => $symfonyRequirements->getRecommendations(),
		'checkPassed' => $checkPassed
	));
});

$app->get('/license', function (Request $request) use ($app) {
	$form = $app['form.factory']->createNamedBuilder('license', 'form', array())
	    ->add('accept_terms', 'choice', array(
		    'choices'   => array('accept_terms'   => 'I accept license terms'),
		    'multiple'  => true,
		    'expanded'  => true,
		    'required' => true,
		    'constraints' => array(new Assert\NotBlank())
		))
	    ->getForm();

    if ('POST' == $request->getMethod()) {
        $form->bind($request);

        if ($form->isValid()) {
        	return $app->redirect($app['url_generator']->generate('prepare'));
    	}
    }

	return $app['twig']->render('license.twig', array('form' => $form->createView()));
})
->assert('_method', 'POST|GET')
->bind('license');

$app->get('/prepare', function (Request $request) use ($app) {
	$app['dispatcher']->dispatch('newscoop.installer.prepare', new GenericEvent());

	$form = $app['form.factory']->createNamedBuilder('db_config', 'form', array(
			'server_name' => 'localhost',
			'database_name' => 'newscoop',
			'server_port' => '3306'
		))
        ->add('server_name', null, array('constraints' => array(new Assert\NotBlank())))
        ->add('server_port', null, array('required' => false))
        ->add('user_name', null, array('constraints' => array(new Assert\NotBlank())))
        ->add('user_password', 'password', array('constraints' => array(new Assert\NotBlank())))
        ->add('database_name', null, array('constraints' => array(new Assert\NotBlank())))
        ->add('override_database', 'choice', array(
		    'choices'   => array('override_database'   => 'Override database'),
		    'multiple'  => true,
		    'expanded'  => true, 
		))
        ->getForm();

    if ('POST' == $request->getMethod()) {
        $form->bind($request);

        if ($form->isValid()) {
            $data = $form->getData();

            try {
			    $app['db']->connect();
			} catch (\Exception $e) {
				if ($e->getCode() == '1049') {
					$app['database_service']->createNewscoopDatabase($app['db']);
				}

				if ($e->getCode() == '1045') {
					$app['session']->getFlashBag()->add('danger', 'Database parameters invalid. Could not connect to database server.');

					return $app['twig']->render('prepare.twig', array('form' => $form->createView()));
				}
			}

			$tables = $app['db']->fetchAll('SHOW TABLES', array());
			if (count($tables) == 0 || $data['override_database'][0]) {
				$app['database_service']->fillNewscoopDatabase($app['db']);
				$app['database_service']->loadGeoData($app['db']);
				$app['database_service']->saveDatabaseConfiguration($app['db']);
				
			} else {
				$app['session']->getFlashBag()->add('danger', '<p>There is already a database named <i>' . $app['db']->getDatabase() . '</i>.</p><p>If you are sure to overwrite it, check <i>Yes</i> for the option below. If not, just change the <i>Database Name</i> and continue.');
			}

            // redirect somewhere
            $app['session']->set('db_data', $data);

            return $app->redirect($app['url_generator']->generate('process'));
        }
    }

	return $app['twig']->render('prepare.twig', array('form' => $form->createView()));
})
->assert('_method', 'POST|GET')
->bind('prepare');

$app->get('/process', function (Request $request) use($app) {
	$app['dispatcher']->dispatch('newscoop.installer.process', new GenericEvent());

	$form = $app['form.factory']->createNamedBuilder('main_config', 'form', array())
        ->add('site_title', null, array('constraints' => array(new Assert\NotBlank())))
        ->add('recheck_user_password', 'repeated', array(
        	'type' => 'password',
		    'invalid_message' => 'The password fields must match.',
		    'options' => array('attr' => array('class' => 'password-field')),
		    'required' => true,
		    'first_options'  => array('label' => 'Password'),
		    'second_options' => array('label' => 'Repeat Password'),
        	'constraints' => array(new Assert\NotBlank()))
        )
        ->add('user_email', null, array('constraints' => array(new Assert\Email())))
        ->getForm();

    if ('POST' == $request->getMethod()) {
        $form->bind($request);

        if ($form->isValid()) {
        	$data = $form->getData();
        	$app['session']->set('main_config', $data);

            return $app->redirect($app['url_generator']->generate('demo-site'));
        }
    }

	return $app['twig']->render('process.twig', array('form' => $form->createView()));
})
->assert('_method', 'POST|GET')
->bind('process');

$app->get('/demo-site', function (Request $request) use($app) {
	$app['dispatcher']->dispatch('newscoop.installer.demo_site', new GenericEvent());
		$sampleTemplates = array(
			'set_quetzal' => array(
				'name' => 'Quetzal',
				'description' => 'Quetzal<br/>Theme for Newscoop Version 4'
			),
			'set_rockstar' => array(
				'name' => 'Rockstar',
				'description' => 'Rockstar<br/>Theme for Newscoop Version 4'
			),
			'set_the_new_custodian' => array(
				'name' => 'The New Custodian',
				'description' => 'The New Custodian<br/>Theme for Newscoop Version 4'
			),
		);

		$form = $app['form.factory']->createNamedBuilder('demo_site', 'form', array(
				'server_name' => 'localhost',
				'database_name' => 'newscoop',
				'server_port' => '3306'
			))
	        ->add('demo_template', 'choice', array(
			    'choices'   => array(
			    	array('no'   => 'No thanks')
			    )+array_map(function($template, $key) {
					return array($key => $template['name']);
				}, $sampleTemplates, array_keys($sampleTemplates)), 
			    'expanded'  => true, 
			))
	        ->getForm();

		if ('POST' == $request->getMethod()) {
	        $form->bind($request);

	        if ($form->isValid()) {
	            $data = $form->getData();

	            if ($data['demo_template'] != 'no') {
	            	$app['database_service']->installSampleData($app['db'], $request->server->get('HTTP_HOST'));
	            	$app['demosite_service']->copyTemplate($data['demo_template']);
	            	$app['demosite_service']->installEmptyTheme();
	            }

	            return $app->redirect($app['url_generator']->generate('post-process'));
	        }
		}

	return $app['twig']->render('demo.twig', array('form' => $form->createView()));
})
->assert('_method', 'POST|GET')
->bind('demo-site');

$app->get('/post-process', function (Request $request) use($app) {

	$app['finish_service']->saveCronjobs($app['db']);
	$app['finish_service']->generateProxies();
	$app['finish_service']->reloadRenditions();
	$app['finish_service']->saveInstanceConfig($app['session']->get('main_config'), $app['db']);


	return $app['twig']->render('post-process.twig', array());
})
->assert('_method', 'POST|GET')
->bind('post-process');

$app->get('/finish', function (Silex\Application $app) {
	return $app['twig']->render('index.twig', array());
});


$app->run();
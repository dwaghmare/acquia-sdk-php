<?php

namespace Acquia\Test\Cloud\Api;

use Acquia\Cloud\Api\Response as CloudResponse;
use Acquia\Cloud\Api\CloudApiClient;
use Acquia\Cloud\Api\CloudApiAuthPlugin;
use Acquia\Common\Json;
use Guzzle\Http\Message\Header;
use Guzzle\Http\Message\Response;
use Guzzle\Plugin\Mock\MockPlugin;

class CloudApiClientTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @return \Acquia\Cloud\Api\CloudApiClient
     */
    public function getCloudApiClient()
    {
        return CloudApiClient::factory(array(
            'base_url' => 'https://cloudapi.example.com',
            'username' => 'test-username',
            'password' => 'test-password',
        ));
    }

    public function getEnvironmentData($stage = 'dev')
    {
        return array(
            'livedev' => 'enabled',
            'db_clusters' => array(1234),
            'ssh_host' => 'server-1.myhostingstage.hosting.example.com',
            'name' => $stage,
            'vcs_path' => ($stage == 'dev') ? 'master' : 'tags/v1.0.1',
            'default_domain' => "mysitegroup{$stage}.myhostingstage.example.com",
        );
    }

    public function getServerData($type = 'web')
    {
        $number = rand(1000,9999);
        $serverName = "{$type}-{$number}";
        $serverIp = rand(1,254) . '.' . rand(1,254);

        $serverData = array(
            'services' => array(),
            'ec2_region' => 'aq-south-1',
            'ami_type' => 'c1.medium',
            'fqdn' => '{$server_name}.myhostingstage.hosting.example.com',
            'name'=> $serverName,
            'ec2_availability_zone' => 'aq-east-1z',
        );

        switch($type) {
            case 'bal':
                $serverData['services']['varnish'] = array(
                    'status' => 'active',
                );
                $serverData['services']['external_ip'] = "172.16.{$serverIp}";
                break;
            case 'web':
                $serverData['services']['web'] = array(
                    'php_max_procs' => '2',
                    'env_status' => 'active',
                    'status' => 'online',
                );
                break;
            case 'db':
                $serverData['services']['database'] = array();
                break;
            case 'free':
            case 'staging':
            case 'ded':
                $serverData['services']['web'] = array(
                    'php_max_procs' => '2',
                    'env_status' => 'active',
                    'status' => 'online',
                );
                $serverData['services']['database'] = array();
                break;
            case 'vcs':
                $serverData['services']['vcs'] = array (
                    'vcs_url' => 'mysite@vcs-1234.myhostingstage.hosting.example.com:mysite.git',
                    'vcs_type' => 'git',
                    'vcs_path' => 'master',
                );
                break;
        }

        return $serverData;
    }

    /**
     * Helper function that returns the event listener.
     *
     * @param \Acquia\Cloud\Api\CloudApiClient $cloudapi
     *
     * @return \Acquia\Cloud\Api\CloudApiAuthPlugin
     *
     * @throws \UnexpectedValueException
     */
    public function getRegisteredAuthPlugin(CloudApiClient $cloudapi)
    {
        $listeners = $cloudapi->getEventDispatcher()->getListeners('request.before_send');
        foreach ($listeners as $listener) {
            if (isset($listener[0]) && $listener[0] instanceof CloudApiAuthPlugin) {
                return $listener[0];
            }
        }

        throw new \UnexpectedValueException('Expecting subscriber Acquia\Cloud\Api\CloudApiAuthPlugin to be registered');
    }

    /**
     * @param \Acquia\Cloud\Api\CloudApiClient $cloudapi
     * @param array $responseData
     */
    public function addMockResponse(CloudApiClient $cloudapi, array $responseData)
    {
        $mock = new MockPlugin();

        $response = new Response(200);
        $response->setBody(Json::encode($responseData));

        $mock->addResponse($response);
        $cloudapi->addSubscriber($mock);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testRequireUsername()
    {
        CloudApiClient::factory(array(
            'password' => 'test-password',
        ));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testRequirePassword()
    {
        CloudApiClient::factory(array(
            'username' => 'test-username',
        ));
    }

    public function testGetBuilderParams()
    {
        $expected = array (
            'base_url' => 'https://cloudapi.example.com',
            'username' => 'test-username',
            'password' => 'test-password',
        );

        $cloudapi = $this->getCloudApiClient();
        $this->assertEquals($expected, $cloudapi->getBuilderParams());
    }

    public function testGetBasePath()
    {
        $cloudapi = $this->getCloudApiClient();
        $this->assertEquals('/v1', $cloudapi->getConfig('base_path'));
    }

    public function testHasAuthPlugin()
    {
        $cloudapi = $this->getCloudApiClient();
        $hasPlugin = (boolean) $this->getRegisteredAuthPlugin($cloudapi);
        return $this->assertTrue($hasPlugin);
    }

    public function testMockCall()
    {
        $cloudapi = $this->getCloudApiClient();

        $mock = new MockPlugin();
        $mock->addResponse(new Response(200));
        $cloudapi->addSubscriber($mock);

        $request = $cloudapi->get('sites');
        $request->send();

        $header = $request->getHeader('Authorization');
        $this->assertTrue($header instanceof Header);
    }

    public function testMockSitesCall()
    {
        $siteName = 'myhostingstage:mysitegroup';
        $responseData = array($siteName);

        $cloudapi = $this->getCloudApiClient();
        $this->addMockResponse($cloudapi, $responseData);

        $sites = $cloudapi->sites();
        $this->assertTrue($sites instanceof CloudResponse\Sites);
        $this->assertTrue($sites[$siteName] instanceof CloudResponse\Site);
    }

    public function testMockSiteCall()
    {
        $siteName = 'myhostingstage:mysitegroup';
        $responseData = array (
            'production_mode' => '1',
            'title' => 'My Site',
            'vcs_type' => 'git',
            'vcs_url' => 'mysitegroup@git.example.com:mysitegroup.git',
            'unix_username' => 'mysitegroup',
            'name' => $siteName,
        );

        $cloudapi = $this->getCloudApiClient();
        $this->addMockResponse($cloudapi, $responseData);

        $site = $cloudapi->site($siteName);
        $this->assertEquals($site['hosting_stage'], 'myhostingstage');
        $this->assertEquals($site['site_group'], 'mysitegroup');
    }

    public function testMockEnvironmentsCall()
    {
        $siteName = 'myhostingstage:mysitegroup';
        $responseData = array (
            $this->getEnvironmentData('dev'),
            $this->getEnvironmentData('test'),
        );

        $cloudapi = $this->getCloudApiClient();
        $this->addMockResponse($cloudapi, $responseData);

        $environments = $cloudapi->environments($siteName);
        $this->assertTrue($environments instanceof CloudResponse\Environments);
        $this->assertTrue($environments['dev'] instanceof CloudResponse\Environment);
        $this->assertTrue($environments['test'] instanceof CloudResponse\Environment);
    }

    public function testMockEnvironmentCall()
    {
        $siteName = 'myhostingstage:mysitegroup';
        $responseData = $this->getEnvironmentData('dev');

        $cloudapi = $this->getCloudApiClient();
        $this->addMockResponse($cloudapi, $responseData);

        $env = $cloudapi->environment($siteName, 'dev');
        foreach($responseData as $key => $value) {
            $this->assertEquals($value, $env[$key]);
        }
    }

    public function testMockInstallDistroByNameCall()
    {
        $siteName = 'myhostingstage:mysitegroup';
        $environment = 'dev';
        $type = 'distro_name';
        $source = 'acquia-drupal-7';

        // Response is an Acquia Cloud Task
        $responseData = array(
            'recipient' => '',
            'created' => time(),
            // The values encoded in the body can come back in any order
            'body' => sprintf('{"env":"%s","site":"%s","type":"%s","source":"%s"}', $environment, $siteName, $type, $source),
            'id' => 12345,
            'hidden' => 0,
            'result' => '',
            'queue' => 'site-install',
            'percentage' => '',
            'state' => 'waiting',
            'started' => '',
            'cookie' => '',
            'sender' => 'cloud_api',
            'description' => "Install {$source} to dev",
            'completed' => '',
        );

        $cloudapi = $this->getCloudApiClient();
        $this->addMockResponse($cloudapi, $responseData);
        $task = $cloudapi->installDistro($siteName, $environment, $type, $source);
        foreach($responseData as $key => $value) {
            $this->assertEquals($value, $task[$key]);
        }
    }

    public function testMockServersCall()
    {
        $siteName = 'myhostingstage:mysitegroup';
        $responseData = array (
            $this->getServerData('bal'),
            $this->getServerData('bal'),
            $this->getEnvironmentData('free'),
            $this->getEnvironmentData('vcs'),
        );

        $cloudapi = $this->getCloudApiClient();
        $this->addMockResponse($cloudapi, $responseData);

        $servers = $cloudapi->servers($siteName, 'dev');
        $this->assertTrue($servers instanceof CloudResponse\Servers);
        $this->assertTrue($servers[$responseData[0]['name']] instanceof CloudResponse\Server);
        $this->assertTrue($servers[$responseData[1]['name']] instanceof CloudResponse\Server);
        $this->assertTrue($servers[$responseData[2]['name']] instanceof CloudResponse\Server);
        $this->assertTrue($servers[$responseData[3]['name']] instanceof CloudResponse\Server);
    }

    public function testMockServerCall()
    {
        $siteName = 'myhostingstage:mysitegroup';
        $responseData = $this->getServerData('free');

        $cloudapi = $this->getCloudApiClient();
        $this->addMockResponse($cloudapi, $responseData);

        $server = $cloudapi->server($siteName, 'dev', 'free');
        $this->assertTrue($server instanceof CloudResponse\Server);
        foreach($responseData as $key => $value) {
            $this->assertEquals($value, $server[$key]);
        }
    }

}

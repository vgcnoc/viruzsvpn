<?php

namespace Tests\Feature;

use App\Models\VpnAccount;
use App\Models\VpnServer;
use App\Services\Servers\MikroTikAdapter;
use Mockery;
use RouterOS\Interfaces\ClientInterface;
use RouterOS\Query;
use Tests\TestCase;

class RouterOsRemoteRulesTest extends TestCase
{
    private function fixture(): array
    {
        $state = (object) ['rules' => ['nat' => [], 'filter' => []], 'nextId' => 1, 'pending' => [], 'failFilter' => false, 'services' => []];
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('query')->andReturnUsing(function (Query $query) use ($state, $client) {
            $endpoint = $query->getEndpoint();
            $properties = [];
            $comment = null;
            foreach ($query->getAttributes() as $attribute) {
                if (str_starts_with($attribute, '=')) {
                    [$key, $value] = explode('=', substr($attribute, 1), 2);
                    $properties[$key] = $value;
                } elseif (str_starts_with($attribute, '?comment=')) {
                    $comment = substr($attribute, 9);
                }
            }
            $state->pending = [];
            if ($endpoint === '/ip/service/print') {
                $state->pending = $state->services;
            }
            if (preg_match('#^/ip/firewall/(nat|filter)/(print|add|set|move|remove)$#', $endpoint, $matches)) {
                [, $section, $action] = $matches;
                if ($action === 'print') {
                    $state->pending = array_values(array_filter($state->rules[$section], fn ($rule) => $comment === null || $rule['comment'] === $comment));
                } elseif ($action === 'add') {
                    if ($section === 'filter' && $state->failFilter) {
                        $state->failFilter = false;
                        throw new \RuntimeException('Simulated RouterOS failure');
                    }
                    $id = '*'.$state->nextId++;
                    $state->rules[$section][$id] = ['.id' => $id] + $properties;
                } elseif ($action === 'set') {
                    $id = $properties['.id'];
                    $state->rules[$section][$id] = array_replace($state->rules[$section][$id], $properties);
                } elseif ($action === 'remove') {
                    unset($state->rules[$section][$properties['.id']]);
                }
            }
            return $client;
        });
        $client->shouldReceive('read')->andReturnUsing(fn () => $state->pending);
        $account = new VpnAccount([
            'uuid' => 'account-uuid', 'allocated_ip' => '10.250.20.10', 'remote_public_port' => 20001,
            'remote_target_port' => 8291, 'remote_allowed_cidr' => '203.0.113.10/32',
            'remote_wan_interface' => 'ether1', 'remote_gateway_ip' => '10.250.20.1',
        ]);
        $account->id = 7;
        return [new MikroTikAdapter($client), $state, new VpnServer, $account];
    }

    public function test_rules_are_restricted_idempotent_and_updated_by_account_comment(): void
    {
        [$adapter, $state, $server, $account] = $this->fixture();
        $this->assertSame('success', $adapter->syncRemoteAccess($server, $account)['status']);
        $account->remote_public_port = 20002;
        $this->assertSame('success', $adapter->syncRemoteAccess($server, $account)['status']);
        $this->assertCount(2, $state->rules['nat']);
        $this->assertCount(1, $state->rules['filter']);
        $dst = collect($state->rules['nat'])->firstWhere('chain', 'dstnat');
        $this->assertSame('20002', $dst['dst-port']);
        $this->assertSame('203.0.113.10/32', $dst['src-address']);
        $this->assertSame('10.250.20.10', $dst['to-addresses']);
        $this->assertSame('8291', $dst['to-ports']);
    }

    public function test_partial_firewall_install_is_cleaned_up_on_failure(): void
    {
        [$adapter, $state, $server, $account] = $this->fixture();
        $state->failFilter = true;
        $this->assertSame('failed', $adapter->syncRemoteAccess($server, $account)['status']);
        $this->assertSame([], $state->rules['nat']);
        $this->assertSame([], $state->rules['filter']);
    }

    public function test_an_unrelated_nat_port_range_is_preserved_and_blocks_the_new_mapping(): void
    {
        [$adapter, $state, $server, $account] = $this->fixture();
        $state->rules['nat']['*old'] = ['.id' => '*old', 'chain' => 'dstnat', 'protocol' => 'tcp', 'dst-port' => '20000-21000', 'comment' => 'existing client'];
        $this->assertSame('failed', $adapter->syncRemoteAccess($server, $account)['status']);
        $this->assertCount(1, $state->rules['nat']);
        $this->assertSame('existing client', $state->rules['nat']['*old']['comment']);
    }

    public function test_chr_service_ports_are_reserved_even_when_disabled(): void
    {
        [$adapter, $state, $server, $account] = $this->fixture();
        $state->services = [['name' => 'winbox', 'port' => '20001', 'disabled' => 'true']];
        $this->assertSame('failed', $adapter->syncRemoteAccess($server, $account)['status']);
        $this->assertSame([], $state->rules['nat']);
        $this->assertContains(20001, $adapter->usedPublicTcpPorts($server)['ports']);
    }
}

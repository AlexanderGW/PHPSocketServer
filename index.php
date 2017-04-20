<?php

/**
 * @project PHP Socket Server - Multiple clients, client broadcasting, shutdown grace period, etc. No forking nessessary!
 * @author Alexander Gailey-White
 *
 * TODO
 * -----------------------
 * 0x06 ACK for client and connection checking?
 */

error_reporting( E_ALL );
define( '__EOL', "\r\n" );
define( '__LOG_READ', 1 );
define( '__LOG_WRITE', 1 );

require_once 'server.php';

set_time_limit( 0 );
ob_implicit_flush();

//var_dump($argv);exit;
Server::start( '0.0.0.0', 2014 );

$_SHUTDOWN_REQ = null;
while( true ) {

	// Shutdown request made, allow clients some time to finish.
	if ( $_SHUTDOWN_REQ and ( time() - $_SHUTDOWN_REQ ) >= Server::SHUTDOWN_GRACE ) {
		break;
	}

	// Check for new connections
	$connection = @socket_accept( Server::getSocket() );
	if ( $connection === false ) {
		usleep( 100 );
	} elseif ( $connection > 0 ) {
		$client = Server::newClient( $connection );
		$client->send( "Welcome to Super Awesome Socket Sever a la PHP! Available commands; broadcast ..., echo ..., info, quit, shutdown, time" );
		unset( $connection );
	} else {
		$client->console( "Connection error: " . socket_strerror( $connection ) . __EOL );
	}

	// Check client chatter
	if ( Server::getNumClients() ) {
		foreach ( Server::getClients() as $uid => $client ) {
			$buffer = $client->read();
			if ( $buffer === '' ) {
				$client->console( "Read error: " . socket_strerror( socket_last_error() ) );
				$client->disconnect();
				Server::removeClient( $uid );
			} elseif ( $buffer ) {
				$buffer = trim( $buffer );

				if ( __LOG_READ == 1 ) {
					$client->console( '<- ' . $buffer );
				}

				// Command: Client disconnect
				if ( $buffer == 'quit' ) {
					$client->console( "Disconnected" );
					$client->disconnect();
					Server::removeClient( $uid );
				} // Command: Server shutdown
				elseif ( $buffer == 'shutdown' ) {
					if ( $_SHUTDOWN_REQ ) {
						$client->send( sprintf( "Shutdown already requested. Shutting down in %d seconds.", ( $_SHUTDOWN_REQ + Server::SHUTDOWN_GRACE ) - time() ) );
					} else {
						Server::broadcast( sprintf( "NOTICE: Server will be shutting down in %d seconds...", Server::SHUTDOWN_GRACE ) );
						$_SHUTDOWN_REQ = time();
					}
				} // Command: Server date/time
				elseif ( $buffer == 'time' ) {
					$client->send( "Server date is " . date( 'c' ) );
				} // Command: Server/client information
				elseif ( $buffer == 'info' ) {
					$client->send( sprintf( "Server running since: %s (%d seconds)", date( 'c', Server::getStartTime() ), ( time() - Server::getStartTime() ) ) );
					$client->send( sprintf( "%d client(s) connected.", Server::getNumClients() ) );
					$client->send( sprintf( '%5s |%15s |%6s', 'Ref', 'Address', 'Port' ) );
					$client->send( str_repeat( '-', 6 ) . '+' . str_repeat( '-', 16 ) . '+' . str_repeat( '-', 6 ) );

					$i = 1;
					foreach ( Server::getClients() as $connection ) {
						$client->send( sprintf( '%5d |%15s |%6d', $i, $connection->getAddress(), $connection->getPort() ) );
						$i ++;
					}
				} // Command: Broadcast message to all clients
				elseif ( strpos( $buffer, 'broadcast ' ) === 0 ) {
					Server::broadcast( $client->getAddress() . ':' . $client->getPort() . ' broadcasted "' . substr( $buffer, 10 ) . '"' );
				} // Command: Echo message
				elseif ( strpos( $buffer, 'echo ' ) === 0 ) {
					$client->send( substr( $buffer, 5 ) );
				} // Command: Unknown
				else {
					$client->send( "Unknown command." );

					// Check for flooding
					if ( $client->getLastFailure() == time() ) {
						Server::console( 'Client flooding ' . $client->getAddress() . ':' . $client->getPort() );
						$client->send( 'Disconnected for flooding.' );
						$client->disconnect();
						Server::removeClient( $uid );
					} else {
						$client->setLastFailure();
					}
					continue;
				}

				//$client->increment( 'success' );
			}
		}
	}
}

Server::console( "Shutdown proceedure begun..." );

// Disconnect clients
if ( Server::getNumClients() ) {
	foreach ( Server::getClients() as $client ) {
		$client->disconnect();
	}
}

// Shutdown
Server::shutdown();
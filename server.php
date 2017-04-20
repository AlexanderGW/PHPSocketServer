 <?php

/**
 * 
 */

/*class Block {
	const BLOCKSIZE = 512;
	private static $handle = null;

	public function open( $key = null ) {
		if( is_null( $key ) )
			return;

		if( is_null( self::$handle ) )
			self::$handle = shmop_open( $key, 'c', 0655, self::BLOCKSIZE );
		if( self::$handle === false )
			return false;

		return true;
	}

	public function get( $key = null, $start = 0, $length = self::BLOCKSIZE ) {
		if( !self::open( $key ) )
			return false;

		$buffer = shmop_read( self::$handle, $start, $length );
		if( $buffer === false )
			return;

		self::close();
		return $buffer;
	}

	public function set( $key = null, $buffer = null, $offset = 0 ) {
		if( !self::open( $key ) )
			return false;

		$length = shmop_write( self::$handle, $buffer, $offset );
		if( $length === false )
			return;

		self::close();
		return $length;
	}

	public function close() {
		shmop_close( self::$handle );
		self::$handle = null;
		return true;
	}

	public function delete() {
		return shmop_delete( self::$handle );
	}
}*/

/**
 * 
 */

class Server {
	const SHUTDOWN_GRACE = 3;
	const PCKT_READ_LEN = 512;
	private static $time = null;
	private static $socket = null;
	private static $clients = array();

	public static function start( $address = '0.0.0.0', $port = null ) {
		self::$time = time();
		echo __EOL . str_repeat( '-', 45 ) . __EOL . " Super Awesome Socket Server" . __EOL . str_repeat( '-', 45 ) . __EOL . __EOL;

		if( ( self::$socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP ) ) < 0 )
			die( "Failed to create socket: " . socket_strerror( self::$socket ) . __EOL );

		if( ( $return = socket_bind( self::$socket, $address, $port ) ) < 0 )
			die( "Failed to bind to socket: " . socket_strerror( $return ) . __EOL );

		if( ( $return = socket_listen( self::$socket ) ) < 0 )
			die( "Failed to listen to socket: " . socket_strerror( $return ) . __EOL );

		//socket_set_option( self::$socket, SOL_SOCKET, SO_RCVTIMEO, array( 'sec' => 10, 'usec' => 10000000 ) );
		//socket_set_option( self::$socket, SOL_SOCKET, SO_SNDTIMEO, array( 'sec' => 10, 'usec' => 10000000 ) );
		socket_set_nonblock( self::$socket );
		socket_getsockname( self::$socket, $address, $port );
		self::console( "Listening on " . $address . ":" . $port . " ..." );
	}

	public static function shutdown() {
		socket_shutdown( self::$socket, 2 );
		socket_close( self::$socket );
		self::console( "Done" );
	}

	public static function getSocket() {
		return self::$socket;
	}

	public static function getStartTime() {
		return self::$time;
	}

    public static function console( $string ) {
		echo "[" . gmdate( 'Y-m-d H:i:s' ) . "] " . $string . __EOL;
	}

    public static function newClient( $socket = null ) {
        if( !is_resource( $socket ) )
            return;

		socket_set_option( $socket, SOL_SOCKET, SO_RCVTIMEO, array( 'sec' => 10, 'usec' => 10000000 ) );
		socket_set_option( $socket, SOL_SOCKET, SO_SNDTIMEO, array( 'sec' => 10, 'usec' => 10000000 ) );
		socket_set_nonblock( $socket );

        $client = new Client( $socket );
		$uid = $client->getUID();
		
		// Already exists, clear it up
		if( self::getClient( $uid ) )
			self::getClient( $uid )->disconnect();
		
		self::$clients[ $uid ] = $client;
		return $client;
    }

	public static function getNumClients() {
		return sizeof( self::$clients );
	}

	public static function getClients() {
        return self::$clients;
	}

	public static function getClient( $uid = null ) {
        if( is_null( $uid ) )
            return;
		if( array_key_exists( $uid, self::$clients ) )
			return self::$clients[ $uid ];
		return false;
	}

	public static function removeClient( $uid ) {
		if( is_null( $uid ) )
            return;

		// Only remove if client has been disconnected.
		$client = self::getClient( $uid );
		if( is_null( $client->getSocket() ) ) {
			unset( self::$clients[ $uid ] );
			return true;
		}
		return false;
	}

	public static function broadcast( $buffer ) {
		if( !self::getNumClients() )
			return;
		foreach( self::getClients() as $uid => $client )
			$client->send( $buffer );
		return true;
	}
}

/**
 * 
 */

class Client {
	private $socket = null;
    private $address = null;
    private $port = null;
    private $lastFailure = null;

	function __construct( $socket = null ) {
		$this->socket = $socket;
		socket_getpeername( $this->socket, $this->address, $this->port );
		$this->console( "Connected" );
	}

	public function getSocket() {
		return $this->socket;
	}

	public function getAddress() {
		return $this->address;
	}

	public function getPort() {
		return $this->port;
	}

	public function getUID() {
		return md5( $this->address . ":" . $this->port );
	}

	public function console( $string ) {
		Server::console( $this->address . ":" . $this->port . " " . $string );
	}

	public function read() {
		return socket_read( $this->socket, Server::PCKT_READ_LEN );
	}

	public function send( $buffer = null ) {
		if( is_null( $buffer ) )
			return;

		$buffer .= __EOL;
		$length = strlen( $buffer );
		while( true ) {
			$sent = socket_write( $this->socket, $buffer, $length );
			if( $sent === false ) {
				$this->console( sprintf( 'Failed to write buffer of %d bytes', $length ) );
				$this->disconnect();
				Server::removeClient( $this->getUID() );
				break;
			}

			if( __LOG_WRITE == 1 )
				$this->console( "-> " . rtrim( substr( $buffer, 0, $sent ) ) . ( $sent < $length ? __EOL : '' ) );

			if( $sent < $length ) {
				$buffer = substr( $buffer, $sent );
				$length -= $sent;
			} else
				break;
		}
	}

	public function getLastFailure() {
		return $this->lastFailure;
	}

	public function setLastFailure( $time = null ) {
		if( is_null( $time ) )
			$time = time();
		$this->lastFailure = $time;
	}

	public function disconnect() {
		$this->console( "Disconnected" );
		socket_shutdown( $this->socket, 2 );
		socket_close( $this->socket );
		$this->socket = null;
	}
}
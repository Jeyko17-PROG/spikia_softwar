const http = require('http');
const server = http.createServer();
const io = require('socket.io')(server, { 
    cors: { origin: "*", methods: ["GET", "POST"] } 
}); 

server.listen(3000, '0.0.0.0', () => { 
    console.log("===========================================");
    console.log("MOTOR SPIKIA ONLINE - PUERTO 3000"); 
    console.log("==========================================="); 
}); 

io.on('connection', (socket) => { 
    console.log('Cliente conectado'); 
    
    socket.on('mensaje-congreso', (data) => {
        io.emit('mensaje-congreso', data);
    });
});
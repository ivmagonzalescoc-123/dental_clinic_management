const WebSocket = require('ws');
const mysql = require('mysql2/promise');

const wss = new WebSocket.Server({ port: 8080 });
console.log('WebSocket server listening on ws://localhost:8080');

async function createDbPool() {
  return await mysql.createPool({
    host: 'localhost',
    user: 'root',
    password: '',
    database: 'capstone_dental',
    port: 3306,
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0
  });
}

(async () => {
  const pool = await createDbPool();

  // Poll DB and broadcast queue state
  let lastPayload = null;

  async function fetchQueueData() {
    const today = new Date().toISOString().slice(0,10);
    // fetch all today's queue entries with appointment.user_id
    const sql = `SELECT q.checkin_id, q.que_number, q.status AS queue_status, q.checkin_time, a.appointment_id, a.user_id, p.first_name, p.last_name, p.phone_number
                 FROM check_in_queue q
                 JOIN appointments a ON q.appointment_id = a.appointment_id
                 LEFT JOIN patients p ON a.patient_id = p.patient_id
                 WHERE DATE(q.checkin_time) = ?
                 ORDER BY q.que_number ASC`;
    const [rows] = await pool.query(sql, [today]);

    // waiting count
    const [cntRows] = await pool.query("SELECT COUNT(*) AS c FROM check_in_queue WHERE DATE(checkin_time) = ? AND status = 'waiting'", [today]);
    const waitingCount = (cntRows[0] && cntRows[0].c) ? Number(cntRows[0].c) : 0;

    return { queue: rows, count: waitingCount };
  }

  async function broadcastIfChanged() {
    try {
      const payload = await fetchQueueData();
      const text = JSON.stringify({ type: 'queue_update', data: payload });
      if (text !== lastPayload) {
        lastPayload = text;
        wss.clients.forEach(client => {
          if (client.readyState === WebSocket.OPEN) {
            client.send(text);
          }
        });
      }
    } catch (err) {
      console.error('Error fetching/broadcasting queue:', err);
    }
  }

  // run initial and interval
  await broadcastIfChanged();
  setInterval(broadcastIfChanged, 2000);

  wss.on('connection', (ws, req) => {
    console.log('Client connected', req.socket.remoteAddress);
    // send initial snapshot
    (async () => {
      try {
        const payload = await fetchQueueData();
        ws.send(JSON.stringify({ type: 'queue_update', data: payload }));
      } catch (e) { console.error('Error sending initial payload', e); }
    })();

    ws.on('message', message => {
      // No incoming commands required now; log for debugging
      console.log('Received message from client:', message.toString());
    });

    ws.on('close', () => {
      console.log('Client disconnected');
    });
  });
})();

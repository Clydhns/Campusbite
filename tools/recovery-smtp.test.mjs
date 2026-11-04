import {createServer} from 'node:net';
import {spawn} from 'node:child_process';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
for(const mode of ['accept','reject','timeout']) {
  const sockets=new Set(); let delivered='';
  const server=createServer(socket=>{
    sockets.add(socket); socket.on('close',()=>sockets.delete(socket)); socket.on('error',()=>{});
    if(mode==='timeout') return;
    socket.write('220 fixture.example.invalid ready\r\n');
    let buffer='',stage='',message=[];
    socket.on('data',bytes=>{
      buffer+=bytes.toString(); let index;
      while((index=buffer.indexOf('\r\n'))>=0) {
        const line=buffer.slice(0,index); buffer=buffer.slice(index+2);
        if(stage==='data') {
          if(line!=='.') {message.push(line);continue;}
          delivered=message.join('\r\n'); stage=''; socket.write('250 2.0.0 accepted\r\n');
        } else if(stage==='user') {
          assert.equal(Buffer.from(line,'base64').toString(),'sender@example.invalid');
          stage='password'; socket.write('334 UGFzc3dvcmQ6\r\n');
        } else if(stage==='password') {
          assert.equal(Buffer.from(line,'base64').toString(),'abcdefghijklmnop'); stage='';
          socket.write(mode==='reject'?'535 5.7.8 rejected\r\n':'235 2.7.0 authenticated\r\n');
        } else if(line.startsWith('EHLO')) socket.write('250-fixture.example.invalid\r\n250 AUTH LOGIN\r\n');
        else if(line==='AUTH LOGIN') {stage='user';socket.write('334 VXNlcm5hbWU6\r\n');}
        else if(line==='DATA') {stage='data';socket.write('354 send data\r\n');}
        else if(line==='QUIT') socket.end('221 bye\r\n');
        else socket.write('250 OK\r\n');
      }
    });
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const started=Date.now();
  try {
    const result=await new Promise((resolve,reject)=>{
      // Match the reported production problem: cURL SMTP functions are unavailable.
      const child=spawn(process.env.PHP_BINARY || 'C:/xampp/php/php.exe',
        ['-d','disable_functions=curl_version,curl_init',path.join(root,'tools/tests/recovery-smtp.php'),String(server.address().port)],
        {cwd:root,windowsHide:true,env:{...process.env,SMTP_USERNAME:'',SMTP_PASSWORD:''}});
      let stdout='',stderr=''; const timer=setTimeout(()=>{child.kill();reject(new Error('SMTP probe timed out'));},10000);
      child.stdout.on('data',data=>stdout+=data); child.stderr.on('data',data=>stderr+=data);
      child.on('error',reject);
      child.on('close',code=>{clearTimeout(timer);if(code!==0) reject(new Error(stderr));else {try{resolve(JSON.parse(stdout));}catch(error){reject(error);}}});
    });
    assert.equal(result.secure_defaults,true);
    assert.equal(result.accepted,mode==='accept');
    if(mode==='accept') {
      assert.match(delivered,/Subject: Fixture recovery email/);
      assert.match(delivered,/Content-Transfer-Encoding: base64/);
      assert.match(delivered,/Content-Type: multipart\/alternative/);
      assert.match(delivered,/Content-Type: text\/plain/);
      assert.match(delivered,/Content-Type: text\/html/);
      assert.doesNotMatch(delivered,/Content-Type: image\//);
      assert.doesNotMatch(delivered,/Content-ID:|Content-Disposition: (?:inline|attachment)/);
      const bodies=[...delivered.matchAll(/Content-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+/=\r\n]+?)(?=\r\n--)/g)]
        .map(match=>Buffer.from(match[1].replace(/\s/g,''),'base64').toString());
      assert.ok(bodies.some(body=>body.includes('Fixture message only.')));
      assert.ok(bodies.some(body=>body.includes('>Reset password</a>')));
      assert.ok(bodies.some(body=>body.includes('https://campusbite-orpin.vercel.app/assets/campusbite-email.png?v=')));
      assert.match(delivered,/From: CampusBite <sender@example.invalid>/);
    }
    if(mode==='reject') assert.equal(result.smtp_status,535);
    if(mode==='timeout') assert.ok(Date.now()-started<7000,'SMTP exchange respects its deadline');
    console.log('PASS: socket SMTP '+mode+' with cURL disabled and secure production defaults');
  } finally {
    for(const socket of sockets) socket.destroy();
    await new Promise(resolve=>server.close(resolve));
  }
}

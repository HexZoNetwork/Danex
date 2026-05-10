# Cara Install
---
Cara install danex tuh simple
Bahan Yang Lu Butuhkan:
- Vps [ udah install pterodactyl ]
- SFTP SSH Connection [ Recomended: Termius ]
Cara Masangnya:
- Edit terlebih dulu config.json
Most Important:
```Json
"telegram" : {
      "channel" : "@usernamechannel",
      "chat_id" : "id channel -100xxx", // id channel report
      "creator" : "@username lu",
      "report_channel" : "@report channel",
      "token" : "token bot"
   }
```
```Json
      "rce_control_key" : "xxxx",
      "unblock_portal_token" : "xxxx"
      "waf_challenge_secret" : "xxxxx",
```
```Json
"ptlc" : {
      "api_key" : "pltc bukan plta",
      "url" : "web panel lu"
   },
```
Yang sering kelupaan:
- Admin kan bot di channel report
- make plta sebagai pltc
- id channel salah
- naro node di url web
- Key/Token memakai spasi [ recomended token use _ dari pada spasi ]
Contoh Issue yang sering terjadi:
- lupa cert node
Fix: certbot certonly --nginx -d node.domain 
Note: Domain Sesuai yang di config.yml wings atau di admin/nodes/idnode

- port closed
Fix: ufw allow 8080 2022 80 8080/tcp 2022/tcp 18444 18444/tcp 18443 18443/tcp

- Websocket & Wings Mati
Fix: Di ip:18443/?token=Token_Di_Config lalu cari ip vps kamu dan pencet tombol allow list allu unblock
Jika masih merah atau error websocket/wings hubungi gewe

---

# Update Issue
System update kami bukan update v1 / v2 tapi v1.0 jadi bagi yang beli no up itu masih free update sampai vps mati atau sudah berganti versi Mother [ V1.x atau V2.x]

# CopyRight
- Dane Everitt as Creator of pterodactyl
- https://pterodactyl.io/ as MiT License holder
- HexZo & Dann as Developer or Contributor

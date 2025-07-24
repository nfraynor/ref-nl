<?php

require_once __DIR__ . '/../utils/db.php'; // Assumes db.php sets up PDO using config/database.php

$pdo = Database::getConnection();

// ----- Function to generate a version 4 UUID -----
function generate_uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ----- Function to parse referee name -----
function parse_referee_name($name_str) {
    if (preg_match('/^([^,]+),\s*([^()]+)\s*\(([^)]+)\)/', $name_str, $matches)) {
        $surname = trim($matches[1]);
        $initials = trim($matches[2]);
        $first_name = trim($matches[3]);

        // Handle Dutch prefixes in initials
        if (preg_match('/([A-Z.]+)\s+(van|de|der|van der|van de|den)\s*$/i', $initials, $prefix_matches)) {
            $prefix = $prefix_matches[2];
            $last_name = $prefix . ' ' . $surname;
        } else {
            $last_name = $surname;
        }
    } else {
        // Fallback: treat whole string as first_name, empty last_name
        $first_name = trim($name_str);
        $last_name = '';
    }

    return ['first_name' => $first_name, 'last_name' => $last_name];
}

// ----- Referee data array (from Excel/CSV, excluding header) -----
$referee_rows = [
    ['Name' => 'Aart, W van der (Wesley)', 'Email' => 'wvanderaart@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (BASSETS RC THE)'],
    ['Name' => 'Ankone, B (Bouke)', 'Email' => 'bouke.ankone@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Oost', 'Club' => ''],
    ['Name' => 'Assman, M.J. (Michael)', 'Email' => 'michael.assman67@gmail.com', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Noord West', 'Club' => ''],
    ['Name' => 'Backer, J. (Jeffry)', 'Email' => 'j.backer@ziggo.nl', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Noord West', 'Club' => 'Recreant (DEN HELDER RC)'],
    ['Name' => 'Barnhoorn, S (Serge)', 'Email' => 'serge@barnhoorn.eu', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (VRN)'],
    ['Name' => 'Bett, T (Tim)', 'Email' => 'timbett@xs4all.nl', 'Class' => 'Beoordelaar', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Binns, T (Thomas)', 'Email' => 'thomas.binns@hotmail.co.uk', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Zuid', 'Club' => ''],
    ['Name' => 'Blaas, C.L.N.M. (Kees)', 'Email' => 'keesblaas@planet.nl', 'Class' => 'Scheidsrechter coach', 'District' => 'District Midden', 'Club' => 'Tientjeslid (Utrechtse Rugby Club)'],
    ['Name' => 'Bras, M. (Marit)', 'Email' => 'maritbras@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Noord West', 'Club' => 'Spelend lid (HAARLEM RFC)'],
    ['Name' => 'Broek, A. van den (Arne)', 'Email' => 'arne.broek@kpnmail.nl', 'Class' => 'Beoordelaar', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (RCE (RC Eindhoven))'],
    ['Name' => 'Broersma, O. (Obed)', 'Email' => 'obroersma@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Bronkhorst, C.G. (Carl Garth)', 'Email' => 'carl.bronkz101@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (DUKES RC THE)'],
    ['Name' => 'Brucciani, T (Thomas)', 'Email' => 'tom@brucciani.co.uk', 'Class' => 'Extra', 'District' => 'District Oost', 'Club' => ''],
    ['Name' => 'Bruijn, P. (Paul)', 'Email' => 'paulbruyn@gmail.com', 'Class' => 'Scheidsrechter coach', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (Rotterdamse Rugby Club)'],
    ['Name' => 'Bruin, E. de (Ed)', 'Email' => 'eddydebruin@xs4all.nl', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Midden', 'Club' => ''],
    ['Name' => 'Brummelman, M (Marloes)', 'Email' => 'marloes.brummelman@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Zuid', 'Club' => 'Spelend lid (M.M.R.C.)'],
    ['Name' => 'Buist, M. (Marinus)', 'Email' => 'marinus.buist@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Oost', 'Club' => 'Recreant (Rugbyclub The Big Bulls)'],
    ['Name' => 'Burbach, M (Max)', 'Email' => 'mh.burbach@gmx.de', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Oost', 'Club' => 'Tientjeslid (Rugby Club Aachen e.V)'],
    ['Name' => 'Buys, J.C. (Chris)', 'Email' => 'chrisbuys22@gmail.com', 'Class' => 'Extra', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (OEMOEMENOE EZRC)'],
    ['Name' => 'Capello, A (Ad)', 'Email' => 'wedstrijdsecretaris@rugbyroosendaal.nl', 'Class' => 'Beoordelaar', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (RC RCC )'],
    ['Name' => 'Coronel, S (Stefan)', 'Email' => 'steef.coronel@gmail.com', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (ASCRUM A.S.R.V.)'],
    ['Name' => 'D\'Ambrosio, F (Federico)', 'Email' => 'federico@dambrosio.nl', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Midden', 'Club' => 'Tientjeslid (VRN)'],
    ['Name' => 'Denley, B (Brian)', 'Email' => 'bddenley@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (GOUDA RFC)'],
    ['Name' => 'Dijkstra, M. (Mireya)', 'Email' => 'mireyadijk@gmail.com', 'Class' => 'Extra', 'District' => 'District Noord', 'Club' => 'Tientjeslid (GREATE PIER RC)'],
    ['Name' => 'Duiverman, K.J. (Kees Jan)', 'Email' => 'kees.jan.duiverman@gmail.com', 'Class' => 'Beoordelaar', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Engelse, L den (Lucas)', 'Email' => 'lucas004848@gmail.com', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Midden', 'Club' => 'Tientjeslid (NIEUWEGEIN RC)'],
    ['Name' => 'Estanga, Y (Yeraldin)', 'Email' => 'yeral.rugby.referee@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (VRN)'],
    ['Name' => 'Faassen, R. van (Rutger)', 'Email' => 'rutgervf@gmail.com', 'Class' => 'Extra', 'District' => 'District Oost', 'Club' => 'Recreant (Rugbyclub The Big Bulls)'],
    ['Name' => 'Gerwen, LLF van (Louis)', 'Email' => 'louisvangerwen@hotmail.com', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (TSRC TARANTULA)'],
    ['Name' => 'Glaser, S. (Stephan)', 'Email' => 'stephan@glsr.nl', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Noord West', 'Club' => ''],
    ['Name' => 'Grillis, R.M.F. (Ruben)', 'Email' => 'ruben.grillis@hotmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Oost', 'Club' => ''],
    ['Name' => 'Hansmeier, A (Annabell)', 'Email' => 'annabellhansmeier@gmx.de', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (SMUGGLERS RC THE)'],
    ['Name' => 'Hawkins, M (Mike)', 'Email' => 'lostohawk@live.com', 'Class' => 'Extra', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (VRN)'],
    ['Name' => 'Heijer, E. den (Eelco)', 'Email' => 'eelcodh@hotmail.com', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (DELFT RC)'],
    ['Name' => 'Heuff, D (Dirk)', 'Email' => 'djheuff@gmail.com', 'Class' => 'Scheidsrechter coach', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (Rotterdamse Studenten Rugby Club)'],
    ['Name' => 'Hoed, M. van den (Mattijs)', 'Email' => 'mattijsvandenhoed@freedom.nl', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Noord', 'Club' => 'Social Rugby (GRONINGEN RC)'],
    ['Name' => 'Hoyer, M (Mike)', 'Email' => 'referee@rugby-club-aachen.de', 'Class' => 'Scheidsrechter coach', 'District' => 'District Zuid', 'Club' => ''],
    ['Name' => 'Huiskamp, H.W. (Erwin)', 'Email' => 'h.w.huiskamp@gmail.com', 'Class' => 'Beoordelaar', 'District' => 'District Midden', 'Club' => 'Tientjeslid (GOOI RC \'T)'],
    ['Name' => 'Lancashire, R (Richard)', 'Email' => 'richard.lancashire@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Zuid West', 'Club' => 'Recreant (RC DIOK)'],
    ['Name' => 'Letaif, W (Wissem)', 'Email' => 'wissemltaifrugby@gmail.com', 'Class' => 'A: Ereklasse heren', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (AMSTERDAMSE AC)'],
    ['Name' => 'Looten, L (Lars)', 'Email' => 'lad.looten@gmail.com; elooten@gmail.com', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Oost', 'Club' => 'Spelend lid (WASPS NRC THE)'],
    ['Name' => 'Maaijen, K. (Koen)', 'Email' => 'k.maaijen@gmail.com', 'Class' => 'A: Ereklasse heren', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (GOUDA RFC)'],
    ['Name' => 'Maintz, A (Antoine)', 'Email' => 'antoine.maintz@upcmail.nl', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Midden', 'Club' => 'Tientjeslid (Rugby Club Hilversum)'],
    ['Name' => 'Meijer, H.A.W. (Riëtte)', 'Email' => 'riettemeijer@gmail.com', 'Class' => 'Extra', 'District' => 'District Midden', 'Club' => 'Tientjeslid (RUS)'],
    ['Name' => 'Meyer, PJ John (Phillip)', 'Email' => 'phillipmeyer22@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Oost', 'Club' => 'Recreant (PIGS ARC THE)'],
    ['Name' => 'Mostert, R. (Reinier)', 'Email' => 'reintjemos@hotmail.com', 'Class' => 'Extra', 'District' => 'District Zuid West', 'Club' => 'Recreant (Rugbyclub Hoek van Holland)'],
    ['Name' => 'Naulais, M.J.R. (Mika)', 'Email' => 'm.naulais@gmail.com', 'Class' => 'Extra', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (L.S.R.G.)'],
    ['Name' => 'O Shaughnessy, COS (Colin)', 'Email' => 'colinoshocks@gmail.com', 'Class' => 'Extra', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (BASSETS RC THE)'],
    ['Name' => 'Oliver, A. (Andrew)', 'Email' => 'aforugbyref@hotmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (OISTERWIJK OYSTERS RFC)'],
    ['Name' => 'Oudman, BJ (Bram)', 'Email' => 'bram.oudman.referee@gmail.com', 'Class' => 'A: Ereklasse heren', 'District' => 'District Oost', 'Club' => 'Recreant (EMMEN RUGBY CLUB)'],
    ['Name' => 'O’Connell, D (Dan)', 'Email' => 'daniel.x.oconnell@gmail.com', 'Class' => 'A: Ereklasse heren', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (Rugby Club Aachen e.V)'],
    ['Name' => 'Pardede, J.P. (Jens)', 'Email' => 'jens@dengar.nl', 'Class' => 'A: Ereklasse heren', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (GOUDA RFC)'],
    ['Name' => 'Pardede, T.B. (Tjerk)', 'Email' => 'tjerk@dengar.nl', 'Class' => 'Extra', 'District' => 'District Midden', 'Club' => 'Spelend lid (Utrechtse Studenten Rugby Society)'],
    ['Name' => 'Ploeger, P. (Peter)', 'Email' => 'peter.ploeger@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Oost', 'Club' => 'Spelend lid (ERC\'69)'],
    ['Name' => 'Plomp, S (Simon)', 'Email' => 'smn.plomp@gmail.com', 'Class' => 'Beoordelaar', 'District' => 'District Midden', 'Club' => ''],
    ['Name' => 'Pol, R. van de (Rene)', 'Email' => 'renevdpol@hotmail.com', 'Class' => 'Extra', 'District' => 'District Zuid', 'Club' => 'Recreant (OISTERWIJK OYSTERS RFC)'],
    ['Name' => 'Pouwels, T (Thomas)', 'Email' => 'thomas16839@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Prevaes, B. (Bert)', 'Email' => 'bert@prevaes.nl', 'Class' => 'Beoordelaar', 'District' => 'District Midden', 'Club' => ''],
    ['Name' => 'Puijpe, J.J.M.H.A. (Joost)', 'Email' => 'joostpuype@hotmail.com', 'Class' => 'Beoordelaar', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (AMSTELVEENSE RC)'],
    ['Name' => 'Raynor, N (Nathan)', 'Email' => 'n.f.raynor@gmail.com', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Referee 1, E (Exchange)', 'Email' => 'garyjr9515@gmail.com', 'Class' => 'Extra', 'District' => 'District Midden', 'Club' => 'Tientjeslid (VRN)'],
    ['Name' => 'Referee 2, E (Exchange)', 'Email' => 'ondrej947@gmail.com', 'Class' => 'Extra', 'District' => 'District Midden', 'Club' => 'Tientjeslid (VRN)'],
    ['Name' => 'Referee 3, E (Exchange)', 'Email' => 'jonas.dolezil@protonmail.com', 'Class' => 'Extra', 'District' => 'District Midden', 'Club' => 'Tientjeslid (VRN)'],
    ['Name' => 'Riepe, C (Conrad)', 'Email' => 'conrad.riepe@googlemail.com', 'Class' => 'A: Ereklasse heren', 'District' => 'District Zuid', 'Club' => ''],
    ['Name' => 'Ritchie, K (Katherine)', 'Email' => 'katherine.ritchie@btinternet.com', 'Class' => 'A: Ereklasse heren', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (Rotterdamse Studenten Rugby Club)'],
    ['Name' => 'Rooyen, B van (Bruce)', 'Email' => 'brucethomas.vr@gmail.com', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Rouwet, P (Pablo)', 'Email' => 'p.rouwet@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Noord West', 'Club' => 'Recreant (ASCRUM A.S.R.V.)'],
    ['Name' => 'Ruiter, H.L.G. de (Henrie)', 'Email' => 'hlg.ruiter@gmail.com', 'Class' => 'Beoordelaar', 'District' => 'District Zuid', 'Club' => ''],
    ['Name' => 'Smits, P. (Pieter)', 'Email' => 'pag.smits@hotmail.com', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Zuid West', 'Club' => 'Recreant (Rugbyclub Hoek van Holland)'],
    ['Name' => 'Spek, E Van der (Edwin)', 'Email' => 'edwinvdspek.rugby@gmail.com', 'Class' => 'A: Ereklasse heren', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (HAARLEM RFC)'],
    ['Name' => 'Statham, J (James)', 'Email' => 'james.statham1@btinternet.com; claudiastatham@live.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Stevens, A. (Andrew)', 'Email' => 'ajstevens97@outlook.com', 'Class' => 'Beoordelaar', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (OCTOPUS RC)'],
    ['Name' => 'Taljaard, D.J. (Diederick)', 'Email' => 'dj.taljaard@gmail.com', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Teerink, F. (Friso)', 'Email' => 'frisoteerink@gmail.com', 'Class' => 'Beoordelaar', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (RUSH SRC)'],
    ['Name' => 'Velden, R van der (Rudolf)', 'Email' => 'vdvruud@gmail.com', 'Class' => 'Beoordelaar', 'District' => 'District Midden', 'Club' => 'Tientjeslid (Utrechtse Rugby Club)'],
    ['Name' => 'Veldmaat, J. (Joris)', 'Email' => 'j.veldmaat@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Oost', 'Club' => ''],
    ['Name' => 'Verseveld, F.J.A. van (Fred)', 'Email' => 'fred.van.verseveld@gmail.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Midden', 'Club' => 'Tientjeslid (GOOI RC \'T)'],
    ['Name' => 'Visser, G. (Gert)', 'Email' => 'visser.gert.j@gmail.com', 'Class' => 'A: Ereklasse heren', 'District' => 'District Midden', 'Club' => 'Tientjeslid (Utrechtse Rugby Club)'],
    ['Name' => 'Vliet, J. van der (Hans)', 'Email' => 'hnsvdvliet@gmail.com', 'Class' => 'Beoordelaar', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (DELFT RC)'],
    ['Name' => 'Vries, H. de (Henk)', 'Email' => 'rugbyhenk@outlook.com', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Noord', 'Club' => 'Spelend lid (GRONINGEN RC)'],
    ['Name' => 'Vries, M. de (Michael)', 'Email' => 'vries.vries@ziggo.nl', 'Class' => 'C: 2e klasse heren, Colts Plate', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (WATERLAND RC)'],
    ['Name' => 'Wadey, D (Darron)', 'Email' => 'achillesagain@gmail.com', 'Class' => 'Beoordelaar', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (WATERLAND RC)'],
    ['Name' => 'Wartena, S. (Sjoerd)', 'Email' => 'swartena@casema.nl', 'Class' => 'Beoordelaar', 'District' => 'District Zuid', 'Club' => 'Recreant (Etten-Leur RC)'],
    ['Name' => 'Weir, D (Dennis)', 'Email' => 'dennis.weir.rugby@gmail.com', 'Class' => 'Extra', 'District' => 'District Oost', 'Club' => 'Tientjeslid (VRN)'],
    ['Name' => 'Welle Donker, G. (Guus)', 'Email' => 'guuswelledonker@gmail.com', 'Class' => 'B: Ereklasse dames, 1e klasse heren, Colts cup', 'District' => 'District Zuid West', 'Club' => 'Recreant (WRC-Te Werve RUFC)'],
    ['Name' => 'Wolfenden, I. (Ian)', 'Email' => 'klmblue69@hotmail.com', 'Class' => 'Extra', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (AMSTELVEENSE RC)'],
    ['Name' => 'Wright, L (Liam)', 'Email' => 'liamewright@gmail.com', 'Class' => 'A: Ereklasse heren', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (HAARLEM RFC)'],
    ['Name' => 'Zandvliet, J.P. (Joop)', 'Email' => 'referee@joopzandvliet.nl', 'Class' => 'Beoordelaar', 'District' => 'District Noord West', 'Club' => 'Spelend lid (ALKMAARSE R.U.F.C.)'],
    ['Name' => 'Clarke, D (Don)', 'Email' => 'don_clarke_bss@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Midden', 'Club' => 'Tientjeslid (RUS)'],
    ['Name' => 'Doughty, M.J. (Martin)', 'Email' => 'doughtymartin@aol.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Midden', 'Club' => 'Tientjeslid (Utrechtse Rugby Club)'],
    ['Name' => 'Feller, MM (Max)', 'Email' => 'max.feller@kpnmail.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Midden', 'Club' => 'Spelend lid (BULLDOGS ALMERE RC)'],
    ['Name' => 'Hagens, L.G.L.M. (Luuk)', 'Email' => 'luukhagens@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Midden', 'Club' => 'Tientjeslid (Stichtsche Rugby Football Club)'],
    ['Name' => 'Koops, K (Klaas)', 'Email' => 'koopsklaas@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Midden', 'Club' => 'Recreant (SPAKENBURG RC)'],
    ['Name' => 'Luteijn, E.P.A. (Eric)', 'Email' => 'eluteijn@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Midden', 'Club' => 'Tientjeslid (GOOI RC \'T)'],
    ['Name' => 'Onsoz, A (Aylin)', 'Email' => 'aylinonsoz@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Midden', 'Club' => 'Tientjeslid (Utrechtse Rugby Club)'],
    ['Name' => 'Ruijter, SM de (Shaquil)', 'Email' => 'shaquil11@outlook.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Midden', 'Club' => 'Spelend lid (Rugby Club Eemland)'],
    ['Name' => 'Silbernberg, AP (Allain)', 'Email' => 'allains27@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Midden', 'Club' => ''],
    ['Name' => 'Tolboom, A.G. (Ton)', 'Email' => 'tontolboom@outlook.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Midden', 'Club' => ''],
    ['Name' => 'Verveer, H (Hans)', 'Email' => 'hlverveer@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Midden', 'Club' => 'Tientjeslid (SPAKENBURG RC)'],
    ['Name' => 'Albers, J (Jan)', 'Email' => 'jan.albers@icloud.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (ASCRUM A.S.R.V.)'],
    ['Name' => 'Beltman, A. (Arend)', 'Email' => 'beltmanarend@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord West', 'Club' => ''],
    ['Name' => 'Bras, M (Martijn)', 'Email' => 'brasvaneden@upcmail.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (HAARLEM RFC)'],
    ['Name' => 'Fellenberg Van der Molen, A (Andres)', 'Email' => 'a.fellenberg@green-partner.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord West', 'Club' => ''],
    ['Name' => 'Fellenberg van der Molen, L (Lucas)', 'Email' => 'lucas.fellenberg@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord West', 'Club' => 'Spelend lid (Zaandijk Rugby)'],
    ['Name' => 'Goos, H.J. (Hendrik)', 'Email' => 'hendrikgoos@yahoo.co.uk', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (RUSH SRC)'],
    ['Name' => 'Hille Ris Lambers, T (Ties)', 'Email' => 'tieshrl@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (Rotterdamse Studenten Rugby Club)'],
    ['Name' => 'Keyser, G (Gawie)', 'Email' => 'gawie.keyser@me.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (ALKMAARSE R.U.F.C.)'],
    ['Name' => 'Kouwenhoven, I (Ino)', 'Email' => 'ino@quicknet.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord West', 'Club' => 'Tientjeslid (ALKMAARSE R.U.F.C.)'],
    ['Name' => 'Mooij, S de (Sem)', 'Email' => 'semdemooij@icloud.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord West', 'Club' => 'Spelend lid (CASTRICUMSE RC), Tientjeslid (WATERLAND RC)'],
    ['Name' => 'Oosterbeek, S.C.M. (Steijn)', 'Email' => 'frank.oosterbeek@gmail.com; steijnoosterbeek@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord West', 'Club' => 'Spelend lid (AMSTELVEENSE RC)'],
    ['Name' => 'Victor, JA (Jaco)', 'Email' => 'jaco.victor19@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord West', 'Club' => ''],
    ['Name' => 'Butselaar, B.P. van (Bob)', 'Email' => 'bvb88@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => ''],
    ['Name' => 'Delft, ALJ van (Amber)', 'Email' => 'a.l.j.vandelft@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => 'Recreant (GRONINGEN RC)'],
    ['Name' => 'Denkers, R (Rick)', 'Email' => 'drsdenkers@outlook.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => 'Tientjeslid (EMMEN RUGBY CLUB)'],
    ['Name' => 'Eijnatten, M van (Maurits)', 'Email' => 'combustiblewater@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => 'Social Rugby (GRONINGEN RC)'],
    ['Name' => 'Hokse, H.F. (Harro)', 'Email' => 'Voorzitter@thebigstones.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => 'Spelend lid (Rugby Club The Big Stones)'],
    ['Name' => 'Jaspers Focks, L.M (Loes)', 'Email' => 'loesjf@live.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => 'Recreant (GRONINGEN RC)'],
    ['Name' => 'Kizito Bugembe, D (Deusdedit)', 'Email' => 'deuskhalifa@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => 'Social Rugby (Rugby Club Sneek)'],
    ['Name' => 'Pas, T.H. ten (Tom)', 'Email' => 'tomtpas@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => 'Spelend lid (GRONINGEN RC)'],
    ['Name' => 'Roling, A.H.P. (Albert)', 'Email' => 'ahp.roling@ziggo.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => ''],
    ['Name' => 'Skilton, D (David)', 'Email' => 'david3467@live.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => 'Recreant (PHOENIX DRC)'],
    ['Name' => 'Sondorp, LHJ (Luc)', 'Email' => 'l.h.j.sondorp@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => 'Spelend lid (GRONINGEN RC)'],
    ['Name' => 'Stoeten, HJ (Hendrik Jan)', 'Email' => 'hendrikjanstoeten@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => 'Spelend lid (FEANSTER RC)'],
    ['Name' => 'Verlaan, A. (Arthur)', 'Email' => 'a.verlaan@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => ''],
    ['Name' => 'Vries, GP De (GP)', 'Email' => 'rugbygp57@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => ''],
    ['Name' => 'Wagemakers, A (Ad)', 'Email' => 'ad.jeanneke.wagemakers@planet.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => 'Veteranen Rugby (Rugby Club The Big Stones)'],
    ['Name' => 'Weenink, Y. (Yoeri)', 'Email' => 'yweenink@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Noord', 'Club' => 'Recreant (DWINGELOO RC)'],
    ['Name' => 'Ansell, D.J. (Derek)', 'Email' => 'derek_ansell@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => ''],
    ['Name' => 'Brakel, G. van (Gerard)', 'Email' => 'Molenweg10@me.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Recreant (ASCRUM A.S.R.V.)'],
    ['Name' => 'Brinkman, N (Nouri)', 'Email' => 'nourinouri@live.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Tientjeslid (PIGS ARC THE)'],
    ['Name' => 'Dommelen, J van (Jeroen)', 'Email' => 'jeroendommel@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Recreant (WAGENINGEN RC)'],
    ['Name' => 'Ehren, S (Stijn)', 'Email' => 'stijn.ehren@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Recreant (ASCRUM A.S.R.V.)'],
    ['Name' => 'Francke, K (Kai)', 'Email' => 'kaifrancke@hotmail.de', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => ''],
    ['Name' => 'Koeverden, M. van (Michiel)', 'Email' => 'michielvankoeverden@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Tientjeslid (ZWOLLE RC)'],
    ['Name' => 'Kramer, J.A. (Jolanda)', 'Email' => 'jolanda.kramer@outlook.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Tientjeslid (PIGS ARC THE)'],
    ['Name' => 'Luijkx, P.C.L.M. (Patrick)', 'Email' => 'luijkx@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Tientjeslid (OBELIX NSRV)'],
    ['Name' => 'Murray, L (Lorcan)', 'Email' => 'lorcan.murray@hotmail.co.uk', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Tientjeslid (PIGS ARC THE)'],
    ['Name' => 'Nel, M (Martian)', 'Email' => 'martiannel6@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Spelend lid (PIGS ARC THE)'],
    ['Name' => 'Nijman, J. (John)', 'Email' => 'johnnijman@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => ''],
    ['Name' => 'Popken, D (Daan)', 'Email' => 'daanpopken@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Recreant (ZWOLLE RC)'],
    ['Name' => 'Reinhardt, T (Tiaan)', 'Email' => 'tiaanreinhardt@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Recreant (PIGS ARC THE)'],
    ['Name' => 'Reuvers, P. (Peter)', 'Email' => 'reuverspeter@msn.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Tientjeslid (DRC The Wild Rovers)'],
    ['Name' => 'Roos, M. (Marco)', 'Email' => 'marcoroos0@gmail.com; wedstrijdsecretaris@rcwageningen.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => ''],
    ['Name' => 'Schepman, T.J.J.R (Thijs)', 'Email' => 'thijschepman@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => ''],
    ['Name' => 'Smeets, C. (Cosmo)', 'Email' => 'cosmosmeets@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Recreant (PIGS ARC THE)'],
    ['Name' => 'Utrecht, J van (Johan)', 'Email' => 'jdcvanutrecht@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Tientjeslid (DRC The Wild Rovers)'],
    ['Name' => 'Veldman, R. (Richard)', 'Email' => 'lgveldman@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Oost', 'Club' => 'Recreant (WAGENINGEN RC)'],
    ['Name' => 'Adriaanse, J. (Janwillem)', 'Email' => 'janwillem@gmx.es', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (TILBURG RC)'],
    ['Name' => 'Berg, M van den (Max)', 'Email' => 'm.h.vandenberg@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => ''],
    ['Name' => 'Boers, K (Koen)', 'Email' => 'koenboers@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => ''],
    ['Name' => 'Bogaers, W.J.A.M. (Pim)', 'Email' => 'bogaers@icloud.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Recreant (ASCRUM A.S.R.V.)'],
    ['Name' => 'Bommel, T. van (Twan)', 'Email' => 'bomme01@kpnmail.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (OISTERWIJK OYSTERS RFC)'],
    ['Name' => 'Boom, T van den (Tom)', 'Email' => 'tomvdboom@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Spelend lid (ELEPHANTS ESRC THE)'],
    ['Name' => 'Bouwens, W (Wilco)', 'Email' => 'wilco.bouwens@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (RC RCC )'],
    ['Name' => 'Companjen, T (Tijn)', 'Email' => 'tijncompanjen@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Spelend lid (Utrechtse Studenten Rugby Society)'],
    ['Name' => 'Cremers, B (Bart)', 'Email' => 'bja.cremers@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Spelend lid (BREDASE RUGBY CLUB)'],
    ['Name' => 'Demba, A.G.M. (Aïsha)', 'Email' => 'aishademba@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (ELEPHANTS ESRC THE)'],
    ['Name' => 'Derks, H.P.M. (Harm)', 'Email' => 'harmderks96@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Spelend lid (ELEPHANTS ESRC THE)'],
    ['Name' => 'Hanegraaf, B. (Bram Onno)', 'Email' => 'bramhanegraaf@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Spelend lid (OCTOPUS RC)'],
    ['Name' => 'Jacobi, J (Jeroen)', 'Email' => 'jacobijeroen@gmail.com', 'Class' => 'Extra', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (BREDASE RUGBY CLUB)'],
    ['Name' => 'Koekkoek, E. (Etienne)', 'Email' => 'etiennekoekkoek@gmail.com', 'Class' => 'Extra', 'District' => 'District Zuid', 'Club' => 'Spelend lid (BREDASE RUGBY CLUB)'],
    ['Name' => 'Leenders, P. (Peter)', 'Email' => 'peter.leenders@chello.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => ''],
    ['Name' => 'Mooijman, M.H. (Martijn)', 'Email' => 'mhmooijman76@yahoo.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (VETS VRC THE)'],
    ['Name' => 'Reede, T.C.R (Thies)', 'Email' => 'reedecurfs@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Spelend lid (M.M.R.C.)'],
    ['Name' => 'Renate Janssen, S (Scheidsrechter)', 'Email' => 'renate.janssen@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (ELEPHANTS ESRC THE)'],
    ['Name' => 'Rijo Mato, M (Matias)', 'Email' => 'matias.rijo@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (TSRC TARANTULA)'],
    ['Name' => 'Schalkwijk, J. (Jan)', 'Email' => 'jan.schalkwijk@gmx.net', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => ''],
    ['Name' => 'Schoorel, A (Alex)', 'Email' => 'alex0103schoorel@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Recreant (OISTERWIJK OYSTERS RFC)'],
    ['Name' => 'Têtu, L (Luc)', 'Email' => 'l.tetu@live.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (RC RCC )'],
    ['Name' => 'Vermeulen, M. (Mark)', 'Email' => 'zondag11@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => ''],
    ['Name' => 'Wijdeven, J (Joop)', 'Email' => 'joop.wijdeven@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid', 'Club' => 'Tientjeslid (WAGENINGEN RC)'],
    ['Name' => 'Aldama, E. (Eduardo)', 'Email' => 'eardv@yahoo.es', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (DELFT RC)'],
    ['Name' => 'Angelucci, S (Stefano)', 'Email' => 'stefspace@yahoo.it', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (Voorburgse Rugby Club)'],
    ['Name' => 'Bent, JCP van der (Jeroen)', 'Email' => 'tinekeenjeroen@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (Rugbyclub Hoek van Holland)'],
    ['Name' => 'Bloem, D (Dirk)', 'Email' => 'dirk.c.bloem@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (The Hague Hornets)'],
    ['Name' => 'Brouwer, W.J.J. (Wouter)', 'Email' => 'wouter.brouwer1@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (WRC-Te Werve RUFC)'],
    ['Name' => 'Cuvelier-Paradis, Y (Yannick)', 'Email' => 'yannick.cuvelier@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Driel, G B Van (Bruis)', 'Email' => 'bruis@salax.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Recreant (Rotterdamse Rugby Club)'],
    ['Name' => 'Frankland, R.T. (Richard)', 'Email' => 'Rtfrankland@yahoo.com.au', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Gorkum, T van (Tom)', 'Email' => 'thomasvangorkum@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Guichard, S (Sebastian)', 'Email' => 'sebas.guichard@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (Rotterdamse Rugby Club)'],
    ['Name' => 'Haan, W. de (Wiebe)', 'Email' => 'wiebedehaan1957@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (GOUDA RFC)'],
    ['Name' => 'Hokse, M.F. (Marlou)', 'Email' => 'marlou_hokse@kpnmail.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Recreant (The Hague Hornets), Tientjeslid (Rugby Club The Big Stones)'],
    ['Name' => 'Horst, B van der (Bob)', 'Email' => 'bvdhorst2013@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (Voorburgse Rugby Club)'],
    ['Name' => 'Jansen, WR (Wil)', 'Email' => 'wil@klima.co.za', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Kahana, G. (Guy)', 'Email' => 'guy_kahana@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (Haagsche Rugby Club)'],
    ['Name' => 'Kampen, CD van (Céline)', 'Email' => 'cedi.vankampen@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Kersbergen, A van (Ap)', 'Email' => 'appokers@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (GOUDA RFC)'],
    ['Name' => 'Klijnsma, S.D. (Sebastian)', 'Email' => 's.d.klijnsma@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Krabbendam, J.J.C. (Jeroen)', 'Email' => 'J.Krabbendam2@vandervalknotarissen.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (HERMES RUGBY CLUB)'],
    ['Name' => 'Leeming, W (will)', 'Email' => 'william_leeming@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Marijnissen, J (Jurre)', 'Email' => 'jurre.m95@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (Sanctus Virgilius RC)'],
    ['Name' => 'Merwe, DJ van der (David)', 'Email' => 'davidvdmerwe.nl@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (DORDTSCHE RUGBY CLUB), Tientjeslid (Rotterdamse Rugby Club)'],
    ['Name' => 'Mudde, L. (Lars)', 'Email' => 'lars.mudde@live.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (BASSETS RC THE)'],
    ['Name' => 'Mudde, T.R. (Tim)', 'Email' => 'sassem1965@live.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (BASSETS RC THE)'],
    ['Name' => 'Noort, A.B.J. van (Antonie)', 'Email' => 'antonievannoort@hotmail.com', 'Class' => 'E: 4e klasse heren, 2e klasse dames, Jun.Plate', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (GOUDA RFC)'],
    ['Name' => 'Oostenbroek, H.J. (Hubert)', 'Email' => 'hj@oostenbroek.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Recreant (ASCRUM A.S.R.V.)'],
    ['Name' => 'Petersen, L (Loes)', 'Email' => 'loespetersen@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Recreant (L.S.R.G.)'],
    ['Name' => 'Prein, J (Joep)', 'Email' => 'jprein@xs4all.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Recreant (RC DIOK)'],
    ['Name' => 'Rasch, T. (Tycho)', 'Email' => 'tycho.rasch@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (DELFT RC)'],
    ['Name' => 'Schoot, G. van der (Gwen)', 'Email' => 'rugbygwen@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (DELFT RC)'],
    ['Name' => 'Slaghek, E.M. (Erik)', 'Email' => 'emslaghek@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (DELFT RC)'],
    ['Name' => 'Smith, O S (Oliver)', 'Email' => 'oliverfreemansmith@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Recreant (RC DIOK)'],
    ['Name' => 'Swinkels, C.W. (Christian)', 'Email' => 'christian@swinkels.email', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Tandon, ST (Suparshav)', 'Email' => 'suparshavt@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (L.S.R.G.)'],
    ['Name' => 'Teijlingen, AS van (Alessio Stèfano)', 'Email' => 'alessiovanteijlingen@live.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (BASSETS RC THE), Tientjeslid (L.S.R.G.)'],
    ['Name' => 'Thomson, G (Graham)', 'Email' => 'mauritsthomson@outlook.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (L.S.R.G.)'],
    ['Name' => 'Vallinga, Z A (Zoë Anne-Klara)', 'Email' => 'zoe@vallinga.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Recreant (L.S.R.G.)'],
    ['Name' => 'Verbrugh, D.B. (Dagmar)', 'Email' => 'dagmarverbrugh@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (GOUDA RFC)'],
    ['Name' => 'Verweij, B.A. (Bart)', 'Email' => 'bart_verweij@live.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (Sanctus Virgilius RC)'],
    ['Name' => 'Vingerhoets, M. C. H. (Marc)', 'Email' => 'marc.vingerhoets@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (DORDTSCHE RUGBY CLUB)'],
    ['Name' => 'Visser, R (Ruben)', 'Email' => 'Ruben.j.visser@gmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Spelend lid (Rotterdamse Studenten Rugby Club)'],
    ['Name' => 'Voskuil, D (Diederick)', 'Email' => 'diederickvoskuil@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Recreant (DSR-C)'],
    ['Name' => 'Wagner, D (Dennis)', 'Email' => 'denniswagner.bassets@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (BASSETS RC THE)'],
    ['Name' => 'Weightman, PMH (Magnus)', 'Email' => 'mweightman@yahoo.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Recreant (Rotterdamse Rugby Club)'],
    ['Name' => 'Zon, DHM van der (Daan)', 'Email' => 'vanderzon@voorpraktijken.nl', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Zuilen, B.L. van (Bas)', 'Email' => 'bas@vanzuilen.net', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => ''],
    ['Name' => 'Zwet, J van der (Joran)', 'Email' => 'joran1407@hotmail.com', 'Class' => 'D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup', 'District' => 'District Zuid West', 'Club' => 'Tientjeslid (THOR SRC)']
];

// ----- Parse referee rows and build referees_data -----
$referees_data = [];
foreach ($referee_rows as $row) {
    $name_str = $row['Name'];
    $email_str = $row['Email'];
    $class_str = $row['Class'];
    $district = $row['District'];
    $club = $row['Club'];

    // Extract grade
    if (!preg_match('/^([A-E]):/', $class_str, $grade_match)) {
        continue; // Skip if no grade
    }
    $grade = $grade_match[1];

    if (!in_array($grade, ['A', 'B', 'C', 'D'])) {
        continue; // Skip if not A-D
    }

    // Parse name
    $name_parts = parse_referee_name($name_str);
    $first_name = $name_parts['first_name'];
    $last_name = $name_parts['last_name'];

    if (empty($first_name) || empty($last_name)) {
        echo "Warning: Could not parse name from: $name_str\n";
        continue;
    }

    // Extract district name (remove "District ")
    $district_name = trim(str_replace('District ', '', $district));

    // Fetch district_id
    $stmt_district = $pdo->prepare("SELECT id FROM districts WHERE name = ?");
    $stmt_district->execute([$district_name]);
    $district_row = $stmt_district->fetch();
    if (!$district_row) {
        echo "Warning: District '$district_name' not found for referee $name_str. Skipping.\n";
        continue;
    }
    $district_id = $district_row['id'];

    // Get coords or default
    $base_lat = $district_coords[$district_name]['lat'] ?? 52.1326;
    $base_lon = $district_coords[$district_name]['lon'] ?? 5.2913; // Default to Netherlands center
    $home_lat = $base_lat + (mt_rand(-100, 100) / 10000);
    $home_lon = $base_lon + (mt_rand(-100, 100) / 10000);

    // Random AR grade A-D
    $ar_grades = ['A', 'B', 'C', 'D'];
    $ar_grade = $ar_grades[array_rand($ar_grades)];

    // Select primary email (first one if multiple)
    $emails = explode(';', $email_str);
    $primary_email = trim($emails[0]);

    // Build referee data
    $referees_data[] = [
        'uuid' => generate_uuid_v4(),
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $primary_email,
        'phone' => null, // No phone in data
        'home_club_id' => null, // Ignored
        'home_location_city' => null, // Set to null as per fix
        'grade' => $grade,
        'ar_grade' => $ar_grade,
        'home_lat' => $home_lat,
        'home_lon' => $home_lon,
        'district_id' => $district_id
    ];
}

// ----- Seed Referees -----
echo "Seeding Referees...\n";
$seeded_referees_count = 0;
$existing_referees_count = 0;
$stmt_insert_referee = $pdo->prepare("
    INSERT IGNORE INTO referees 
        (uuid, referee_id, first_name, last_name, email, phone, home_club_id, home_location_city, grade, ar_grade, home_lat, home_lon, district_id) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

foreach ($referees_data as $index => $ref) {
    // Generate referee_id
    $referee_id = 'REF' . str_pad($index + 1, 3, '0', STR_PAD_LEFT);

    // Check if exists (by email, assuming unique)
    $stmt_check_referee = $pdo->prepare("SELECT uuid FROM referees WHERE email = ?");
    $stmt_check_referee->execute([$ref['email']]);
    if ($stmt_check_referee->fetch()) {
        $existing_referees_count++;
    } else {
        $stmt_insert_referee->execute([
            $ref['uuid'],
            $referee_id,
            $ref['first_name'],
            $ref['last_name'],
            $ref['email'],
            $ref['phone'],
            $ref['home_club_id'],
            $ref['home_location_city'],
            $ref['grade'],
            $ref['ar_grade'],
            $ref['home_lat'],
            $ref['home_lon'],
            $ref['district_id']
        ]);
        $seeded_referees_count++;
    }
}
echo "Referees: {$seeded_referees_count} seeded, {$existing_referees_count} already existed.\n";

// ----- Seed Referee Weekly Availability -----
echo "Seeding Referee Weekly Availability...\n";
$availability_seeded_count = 0;
$availability_existing_count = 0;
$stmt_insert_availability = $pdo->prepare("
    INSERT IGNORE INTO referee_weekly_availability 
        (uuid, referee_id, weekday, morning_available, afternoon_available, evening_available) 
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmt_check_availability = $pdo->prepare("SELECT COUNT(*) FROM referee_weekly_availability WHERE referee_id = ? AND weekday = ?");

foreach ($referees_data as $ref) {
    for ($weekday = 0; $weekday <= 6; $weekday++) {
        $stmt_check_availability->execute([$ref['uuid'], $weekday]);
        if ($stmt_check_availability->fetchColumn() > 0) {
            $availability_existing_count++;
        } else {
            $availability_uuid = generate_uuid_v4();
            $stmt_insert_availability->execute([
                $availability_uuid,
                $ref['uuid'],
                $weekday,
                true, // morning_available
                true, // afternoon_available
                true  // evening_available
            ]);
            $availability_seeded_count++;
        }
    }
}
echo "Availability: {$availability_seeded_count} seeded, {$availability_existing_count} already existed.\n";

?>
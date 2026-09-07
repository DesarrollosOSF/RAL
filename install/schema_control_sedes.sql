+TRD



}

  inicio_at DATETIME NOT NULL,
  fin_at DATETIME NULL,
  duracion_seg BIGINT NULL,
  evento VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_iu_act (actividades_usuario_id),
  KEY idx_iu_act_fin (actividades_usuario_id, fin_at),
  CONSTRAINT fk_tia_au FOREIGN KEY (actividades_usuario_id) REFERENCES actividades_usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


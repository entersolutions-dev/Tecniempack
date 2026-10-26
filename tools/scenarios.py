import pandas as pd

def calcular_rangos_tecniempack(costo_base_cop, trm_actual, trm_base=4100.0, factor_volatilidad_resina=0.0):
    """
    Calcula las bandas de precios y márgenes de seguridad para Tecniempack
    ajustando por variación en TRM y volatilidad de materia prima.
    """
    # 1. Ajuste por variación de TRM / Macro
    variacion_trm = (trm_actual - trm_base) / trm_base
    
    # El costo ajustado absorbe la variación cambiaria y la volatilidad petroquímica
    costo_ajustado = costo_base_cop * (1 + variacion_trm + factor_volatilidad_resina)
    
    # 2. Definición de Parámetros de la Bandas de Seguridad
    bandas = {
        'Piso Mínimo (Límite Crítico)': {'margen': 0.22, 'descripcion': 'Descuento máximo por volumen alto / Contratos anuales'},
        'Pauta Estándar (Meta Comercial)': {'margen': 0.32, 'descripcion': 'Venta regular en mostrador y lotes medianos B2B'},
        'Techo Máximo (Especial/Bajo Vol.)': {'margen': 0.42, 'descripcion': 'Desarrollos personalizados / Lotes pequeños flexográficos'}
    }
    
    resultados = []
    
    for nivel, config in bandas.items():
        m_target = config['margen']
        
        # Fórmula: Precio = Costo / (1 - % Margen)
        precio_venta = costo_ajustado / (1 - m_target)
        
        # Utilidad / Markup = (Precio - Costo) / Costo
        markup = (precio_venta - costo_ajustado) / costo_ajustado
        
        utilidad_bruta = precio_venta - costo_ajustado
        
        resultados.append({
            'Nivel / Banda': nivel,
            '% Margen': f"{m_target * 100:.1f}%",
            '% Utilidad (Markup)': f"{markup * 100:.1f}%",
            'Precio Venta ($COP)': round(precio_venta, 2),
            'Utilidad Bruta ($COP)': round(utilidad_bruta, 2),
            'Uso Comercial': config['descripcion']
        })
        
    df_resultados = pd.DataFrame(resultados)
    return costo_ajustado, df_resultados

# --- EJEMPLO DE SIMULACIÓN PARA BOLSA CAMISETA 30x60 (BOL-01) ---
COSTO_DIRECTO_HOY = 59500.0  # Costo por millar
TRM_BASE_PROYECTO = 4100.0
TRM_DEL_DIA = 4250.0         # Simulando alza del dólar
VOLATILIDAD_WTI = 0.03       # +3% por alza del crudo/resina

costo_final, df_simulacion = calcular_rangos_tecniempack(
    costo_base_cop=COSTO_DIRECTO_HOY,
    trm_actual=TRM_DEL_DIA,
    trm_base=TRM_BASE_PROYECTO,
    factor_volatilidad_resina=VOLATILIDAD_WTI
)

print("--- SIMULADOR DE RANGOS DE PRECIO - TECNIEMPACK S.A.S. ---")
print(f"Costo Base Original: ${COSTO_DIRECTO_HOY:,.2f} COP")
print(f"TRM Día: ${TRM_DEL_DIA:,.2f} COP | Ajuste Volatilidad Resina: {VOLATILIDAD_WTI*100}%")
print(f"Costo Indexado Ajustado: ${costo_final:,.2f} COP\n")
print(df_simulacion.to_string(index=False))